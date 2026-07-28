<?php

namespace App\Services\Backup;

/**
 * Decides whether a plain-SQL dump is safe to hand to `psql -f`.
 *
 * ══ WHY THIS EXISTS ═══════════════════════════════════════════════════════
 *
 * `psql` is not a SQL interpreter — it is a client with its own command
 * language, and it honours backslash meta-commands found INSIDE the file it
 * is given. `--no-psqlrc` only stops startup files; it does nothing about the
 * script. Verified against PostgreSQL 16.13:
 *
 *   • `\!` in a `-f` script RUNS A LOCAL SHELL COMMAND — arbitrary code
 *     execution from an archive, with the restoring user's privileges, even
 *     with ON_ERROR_STOP=1 and --single-transaction;
 *   • `\connect` / `\c` re-points the session at ANOTHER database, escaping
 *     the "restore only into an empty scratch target" guarantee entirely;
 *   • `\include` / `\i` / `\ir` pull in other local files;
 *   • `\o` redirects output to a file or a pipe; `\copy` reads and writes
 *     local files;
 *   • a bare `COMMIT;` inside the script ends the outer transaction, so the
 *     `--single-transaction` "all or nothing" property silently disappears:
 *     a later failure then leaves a half-restored schema behind (verified —
 *     two tables survived a failed restore).
 *
 * Archive encryption does not help here: it proves confidentiality, not
 * provenance. Anyone who can hand an operator an archive can hand them one of
 * these. So the dump is validated BEFORE psql is started, and anything not
 * proven necessary is refused.
 *
 * ══ THE ALLOWLIST ═════════════════════════════════════════════════════════
 *
 * Determined by inspecting real `pg_dump` output for the supported server
 * (16.13). A plain dump emits exactly three backslash constructs:
 *
 *   • `\.`                  — the COPY-data terminator (data context only);
 *   • `\restrict <token>`   — the framing guard modern pg_dump wraps around
 *   • `\unrestrict <token>`   its output;
 *
 * and nothing else. Those are allowed in their exact generated form, with a
 * strict token shape. Every other meta-command is rejected. This is an
 * allowlist, not a blocklist: a psql command invented tomorrow is refused by
 * default rather than silently permitted.
 *
 * ══ HOW IT SCANS ══════════════════════════════════════════════════════════
 *
 * Not a grep. A bounded, state-aware lexer that understands where a backslash
 * is actually a command, so a dangerous sequence cannot be smuggled through —
 * nor a harmless one misread — inside:
 *
 *   • `--` line comments and nestable block comments;
 *   • ordinary '…' strings ('' escape) and E'…' strings (backslash escapes);
 *   • "…" quoted identifiers;
 *   • $tag$ … $tag$ dollar-quoted function bodies;
 *   • COPY … FROM stdin data blocks, terminated only by a line that is
 *     exactly `\.`.
 *
 * Transaction-control statements are recognised only at STATEMENT START, so
 * `CASE … END` and a PL/pgSQL body's `BEGIN … END` (inside a dollar quote)
 * are not confused with a real `COMMIT;`.
 */
class DumpScriptPolicy
{
    /** Meta-commands a supported pg_dump legitimately emits. */
    public const ALLOWED_META_COMMANDS = ['restrict', 'unrestrict'];

    /** `\restrict` / `\unrestrict` argument shape, as generated. */
    private const RESTRICT_LINE = '/^\\\\(restrict|unrestrict)[ \t]+[A-Za-z0-9]{1,128}[ \t]*$/';

    /**
     * The ONLY accepted COPY form: table-form, FROM stdin, optional column
     * list, optional schema qualification, bare or quoted identifiers.
     */
    private const COPY_FROM_STDIN =
        '/^COPY[ ]+(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*)(?:[ ]*\.[ ]*(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*))?'
        .'(?:[ ]*\([^()]*\))?[ ]+FROM[ ]+stdin[ ]*;?$/i';

    /** Statement-initial keywords that would break the outer transaction. */
    private const TRANSACTION_CONTROL = '/^(begin|commit|rollback|abort|end|start\s+transaction|prepare\s+transaction|savepoint)\b/i';

    /** Default hard ceiling on ONE physical line (bytes). */
    public const DEFAULT_MAX_LINE_BYTES = 1_048_576;

    /** Default hard ceiling on one semicolon-free STATEMENT (bytes). */
    public const DEFAULT_MAX_STATEMENT_BYTES = 4_194_304;

    /** Bytes requested per read. Physical lines are reassembled across chunks. */
    private const READ_CHUNK_BYTES = 65_536;

    /**
     * Only the LEADING bytes of a statement are retained — enough to classify
     * transaction control and the COPY grammar. The rest is counted and
     * discarded, so a semicolon-free statement cannot be used to grow memory.
     */
    private const STATEMENT_KEEP_BYTES = 4_096;

    public function __construct(
        private readonly int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES,
        private readonly int $maxStatementBytes = self::DEFAULT_MAX_STATEMENT_BYTES,
    ) {}

    // Lexer state, carried across lines.
    private bool $inCopy = false;

    private bool $inSingle = false;

    private bool $singleEscapes = false;

    private bool $inDouble = false;

    private int $blockCommentDepth = 0;

    private ?string $dollarTag = null;

    private string $statement = '';

    private int $statementBytes = 0;

    /**
     * Validate the dump at $path, or throw. Streams the file, so a multi-GB
     * dump is scanned without being loaded into memory.
     *
     * @throws RestoreFailure
     */
    public function assertSafe(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw RestoreFailure::content('نسخهٔ پایگاه‌داده قابل بررسی نیست.', 'dump_unreadable');
        }

        $this->reset();

        try {
            // Read in BOUNDED chunks and reassemble physical lines, so neither
            // a gigantic line nor a semicolon-free statement can grow memory
            // without hitting a documented, enforced limit.
            $pending = '';

            while (($chunk = fgets($handle, self::READ_CHUNK_BYTES)) !== false) {
                if (strlen($pending) + strlen($chunk) > $this->maxLineBytes) {
                    throw RestoreFailure::content('ساختار نسخهٔ پایگاه‌داده نامعتبر است.', 'dump_line_too_long');
                }

                $pending .= $chunk;

                if (! str_ends_with($chunk, "\n")) {
                    continue; // physical line continues in the next chunk
                }

                $this->consumeLine(rtrim($pending, "\r\n"));
                $pending = '';
            }

            // Distinguish a clean EOF from a stream failure.
            if (! feof($handle)) {
                throw RestoreFailure::content('نسخهٔ پایگاه‌داده قابل بررسی نیست.', 'dump_read_failed');
            }

            if ($pending !== '') {
                $this->consumeLine(rtrim($pending, "\r\n"));
            }
        } finally {
            fclose($handle);
        }

        // A trailing statement with no terminating semicolon still counts.
        $this->assertStatementAllowed($this->statement);

        if ($this->inSingle || $this->inDouble || $this->dollarTag !== null || $this->blockCommentDepth > 0) {
            throw RestoreFailure::content('ساختار نسخهٔ پایگاه‌داده نامعتبر است.', 'dump_unterminated_literal');
        }
        if ($this->inCopy) {
            throw RestoreFailure::content('ساختار نسخهٔ پایگاه‌داده نامعتبر است.', 'dump_unterminated_copy');
        }
    }

    /** One complete physical line, in whichever lexical state we are in. */
    private function consumeLine(string $line): void
    {
        if ($this->inCopy) {
            // Inside COPY data EVERYTHING is data until a line that is exactly
            // `\.` — including text that looks like SQL or a meta-command.
            if ($line === '\\.') {
                $this->inCopy = false;
                $this->resetStatement();
            }

            return;
        }

        $this->scanLine($line);
    }

    /**
     * Count every significant byte but RETAIN only the leading window needed
     * to classify the statement, and refuse a statement that runs past the
     * configured ceiling without a semicolon.
     */
    private function appendToStatement(string $char): void
    {
        $this->statementBytes += strlen($char);

        if ($this->statementBytes > $this->maxStatementBytes) {
            throw RestoreFailure::content(
                'ساختار نسخهٔ پایگاه‌داده نامعتبر است.',
                'dump_statement_too_long',
            );
        }

        if (strlen($this->statement) < self::STATEMENT_KEEP_BYTES) {
            $this->statement .= $char;
        }
    }

    private function resetStatement(): void
    {
        $this->statement = '';
        $this->statementBytes = 0;
    }

    private function reset(): void
    {
        $this->inCopy = false;
        $this->inSingle = false;
        $this->singleEscapes = false;
        $this->inDouble = false;
        $this->blockCommentDepth = 0;
        $this->dollarTag = null;
        $this->statement = '';
        $this->statementBytes = 0;
    }

    /** Lex one line, carrying quote/comment state across line boundaries. */
    private function scanLine(string $line): void
    {
        $length = strlen($line);
        $i = 0;

        while ($i < $length) {
            $char = $line[$i];
            $next = $line[$i + 1] ?? '';

            if ($this->blockCommentDepth > 0) {
                if ($char === '*' && $next === '/') {
                    $this->blockCommentDepth--;
                    $i += 2;

                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $this->blockCommentDepth++;
                    $i += 2;

                    continue;
                }
                $i++;

                continue;
            }

            if ($this->dollarTag !== null) {
                $tagLength = strlen($this->dollarTag);
                if ($char === '$' && substr($line, $i, $tagLength) === $this->dollarTag) {
                    $this->dollarTag = null;
                    $i += $tagLength;

                    continue;
                }
                $i++;

                continue;
            }

            if ($this->inSingle) {
                if ($this->singleEscapes && $char === '\\') {
                    $i += 2; // E'…' honours backslash escapes

                    continue;
                }
                if ($char === "'") {
                    if ($next === "'") {
                        $i += 2; // '' is a literal quote

                        continue;
                    }
                    $this->inSingle = false;
                }
                $i++;

                continue;
            }

            if ($this->inDouble) {
                // Quoted IDENTIFIER text is kept (unlike string literals, whose
                // contents are data): real dumps quote reserved words, e.g.
                // `COPY public."order" (…) FROM stdin;`, and the COPY grammar
                // below has to see the whole name.
                if ($char === '"') {
                    if ($next === '"') {
                        $this->appendToStatement('""');
                        $i += 2;

                        continue;
                    }
                    $this->inDouble = false;
                }
                $this->appendToStatement($char);
                $i++;

                continue;
            }

            // ── Ordinary SQL context ──────────────────────────────────────

            if ($char === '-' && $next === '-') {
                return; // line comment: nothing else on this line is code
            }

            if ($char === '/' && $next === '*') {
                $this->blockCommentDepth = 1;
                $i += 2;

                continue;
            }

            if ($char === '\\') {
                // A backslash here is a psql META-COMMAND, and psql consumes
                // the rest of the line for it.
                $this->assertMetaCommandAllowed($line, $i);

                return;
            }

            if ($char === "'") {
                $this->inSingle = true;
                // E'…' (or e'…') is the escape-string form.
                $prev = $i > 0 ? $line[$i - 1] : '';
                $this->singleEscapes = ($prev === 'E' || $prev === 'e');
                $this->appendToStatement("'");
                $i++;

                continue;
            }

            if ($char === '"') {
                $this->inDouble = true;
                $this->appendToStatement('"');
                $i++;

                continue;
            }

            if ($char === '$') {
                $tag = $this->dollarQuoteTagAt($line, $i);
                if ($tag !== null) {
                    $this->dollarTag = $tag;
                    $i += strlen($tag);

                    continue;
                }
            }

            if ($char === ';') {
                $this->assertStatementAllowed($this->statement);

                if ($this->statementStartsCopyFromStdin($this->statement)) {
                    // Data starts on the NEXT line.
                    $this->inCopy = true;
                }

                $this->resetStatement();
                $i++;

                continue;
            }

            $this->appendToStatement($char);
            $i++;
        }

        // Statements can span lines; keep them separated by whitespace.
        $this->appendToStatement(' ');
    }

    /** `$tag$` / `$$` opener at $i, or null when this `$` is not one. */
    private function dollarQuoteTagAt(string $line, int $i): ?string
    {
        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)?\$/', substr($line, $i, 128), $m) !== 1) {
            return null;
        }

        return $m[0];
    }

    /**
     * Only the exact meta-commands a supported pg_dump generates survive.
     * Everything else — `\!`, `\connect`, `\i`, `\o`, `\copy`, `\setenv`, and
     * anything unrecognised — is refused before psql ever starts.
     */
    private function assertMetaCommandAllowed(string $line, int $offset): void
    {
        $rest = substr($line, $offset);

        // `\.` outside COPY data is not something a dump legitimately emits.
        if (preg_match('/^\\\\([A-Za-z]+)/', $rest, $m) !== 1) {
            throw RestoreFailure::content(
                'نسخهٔ پایگاه‌داده حاوی دستور کلاینت غیرمجاز است.',
                'meta_command_unknown',
            );
        }

        $name = strtolower($m[1]);

        if (! in_array($name, self::ALLOWED_META_COMMANDS, true)) {
            throw RestoreFailure::content(
                'نسخهٔ پایگاه‌داده حاوی دستور کلاینت غیرمجاز است.',
                'meta_command_'.substr(preg_replace('/[^a-z]/', '', $name) ?? '', 0, 20),
            );
        }

        // Allowed by name — now require the exact generated shape, on its own
        // line, with no trailing payload and no shell substitution.
        if (preg_match(self::RESTRICT_LINE, trim($line)) !== 1) {
            throw RestoreFailure::content(
                'نسخهٔ پایگاه‌داده حاوی دستور کلاینت غیرمجاز است.',
                'meta_command_malformed',
            );
        }
    }

    /** Reject transaction control that would dissolve the outer transaction. */
    private function assertStatementAllowed(string $statement): void
    {
        $normalized = ltrim($statement);
        if ($normalized === '') {
            return;
        }

        if (preg_match(self::TRANSACTION_CONTROL, $normalized) === 1) {
            throw RestoreFailure::content(
                'نسخهٔ پایگاه‌داده کنترل تراکنش دارد و اتمی بودن بازیابی را از بین می‌برد.',
                'transaction_control',
            );
        }
    }

    /**
     * Decide whether a statement is the EXACT table-form `COPY … FROM stdin`
     * that pg_dump emits, and therefore whether the following lines are COPY
     * DATA rather than SQL.
     *
     * Getting this wrong is a remote-code-execution bug, not a parsing nit.
     * The previous broad match ("starts with COPY and contains FROM stdin")
     * was bypassable — verified end to end against PostgreSQL 16.13:
     *
     *     CREATE TABLE stdin (id int);
     *     COPY (SELECT * FROM stdin) TO stdout;
     *     \! touch /tmp/pwned
     *     \.
     *
     * That is QUERY-form COPY writing TO stdout, so psql never reads COPY
     * data — it executes the following `\!` as a client meta-command. The
     * parser, however, believed it was inside a data block and waved the
     * `\!` through as data. The marker file was created.
     *
     * So this is now an exact allowlisted grammar:
     *
     *     COPY <table>[.<table>] [ ( <columns> ) ] FROM stdin ;
     *
     * with identifiers either bare or double-quoted. ANY other COPY form is
     * refused outright — query form, every `TO` form, `FROM PROGRAM`,
     * `TO PROGRAM`, a server-side filename, `WITH (…)` variants, or anything
     * malformed — because none of them are emitted by a supported pg_dump and
     * each either reads/writes server files or leaves psql in a state the
     * parser would model incorrectly.
     *
     * @return bool true when the FOLLOWING lines are COPY data
     */
    private function statementStartsCopyFromStdin(string $statement): bool
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $statement) ?? '');

        if (preg_match('/^COPY\b/i', $normalized) !== 1) {
            return false;
        }

        if (preg_match(self::COPY_FROM_STDIN, $normalized) === 1) {
            return true;
        }

        // It calls itself COPY but is not the one form we accept.
        throw RestoreFailure::content(
            'نسخهٔ پایگاه‌داده حاوی دستور COPY پشتیبانی‌نشده است.',
            'copy_form_unsupported',
        );
    }
}
