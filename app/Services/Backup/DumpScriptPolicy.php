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

    /**
     * Statement-initial role switches. The restore refuses a dangerous role
     * before it starts, so a dump must not be able to BECOME one afterwards.
     */
    private const ROLE_CHANGE = '/^(set\s+role|reset\s+role|set\s+session\s+authorization|reset\s+session\s+authorization|set\s+local\s+role)\b/i';

    /** Default hard ceiling on ONE physical line (bytes). */
    public const DEFAULT_MAX_LINE_BYTES = 1_048_576;

    /** Default hard ceiling on one semicolon-free STATEMENT (bytes). */
    public const DEFAULT_MAX_STATEMENT_BYTES = 4_194_304;

    /** Bytes requested per read. Physical lines are reassembled across chunks. */
    private const READ_CHUNK_BYTES = 65_536;

    public function __construct(
        private readonly int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES,
        private readonly int $maxStatementBytes = self::DEFAULT_MAX_STATEMENT_BYTES,
    ) {}

    // Lexer state, carried across lines.
    private bool $inCopy = false;

    /** Constant-size COPY terminator state (never more than two bytes). */
    private string $copyCandidate = '';

    private bool $copyLineViable = true;

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
            // Outside COPY data, physical lines are reassembled under a hard
            // ceiling. INSIDE COPY data nothing is buffered at all: a constant
            // two-byte state machine looks for a line that is exactly `\.`, so
            // a legitimate multi-megabyte row streams through without tripping
            // the line limit (which previously rejected real backups).
            $buffer = '';

            while (($chunk = fread($handle, self::READ_CHUNK_BYTES)) !== false && $chunk !== '') {
                $length = strlen($chunk);
                $offset = 0;

                while ($offset < $length) {
                    if ($this->inCopy) {
                        $offset = $this->scanCopyData($chunk, $offset, $length);

                        continue;
                    }

                    $newline = strpos($chunk, "\n", $offset);

                    if ($newline === false) {
                        $buffer .= substr($chunk, $offset);
                        $this->assertLineWithinLimit($buffer);
                        $offset = $length;

                        continue;
                    }

                    $buffer .= substr($chunk, $offset, $newline - $offset);
                    $this->assertLineWithinLimit($buffer);
                    $this->scanLine(rtrim($buffer, "\r"));
                    $buffer = '';
                    $offset = $newline + 1;
                }
            }

            // Distinguish a clean EOF from a stream failure.
            if (! feof($handle)) {
                throw RestoreFailure::content('نسخهٔ پایگاه‌داده قابل بررسی نیست.', 'dump_read_failed');
            }

            if ($buffer !== '') {
                $this->scanLine(rtrim($buffer, "\r"));
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

    private function assertLineWithinLimit(string $buffer): void
    {
        if (strlen($buffer) > $this->maxLineBytes) {
            throw RestoreFailure::content('ساختار نسخهٔ پایگاه‌داده نامعتبر است.', 'dump_line_too_long');
        }
    }

    /**
     * Stream COPY data with CONSTANT state. Only two bytes are ever held: the
     * candidate `\.` at the start of the current physical line. Anything else
     * on the line makes it data, so `\.x` stays data and a row of any size is
     * fine. Returns the offset just past the terminator's newline, or the end
     * of the chunk.
     */
    private function scanCopyData(string $chunk, int $offset, int $length): int
    {
        while ($offset < $length) {
            $char = $chunk[$offset];

            if ($char === "\n") {
                $terminator = $this->copyCandidate === '\\.';
                $this->copyCandidate = '';
                $this->copyLineViable = true;
                $offset++;

                if ($terminator) {
                    $this->inCopy = false;
                    $this->resetStatement();

                    return $offset;
                }

                continue;
            }

            if ($this->copyLineViable) {
                if ($char === "\r" && $this->copyCandidate === '\\.') {
                    $offset++; // tolerate CRLF on the terminator line

                    continue;
                }

                $candidate = $this->copyCandidate.$char;
                if ($candidate === '\\' || $candidate === '\\.') {
                    $this->copyCandidate = $candidate;
                } else {
                    $this->copyLineViable = false;
                    $this->copyCandidate = '';
                }
            }

            $offset++;
        }

        return $offset;
    }

    /**
     * Accumulate the statement's significant bytes and refuse one that runs
     * past the configured ceiling without a semicolon. Security classification
     * therefore always sees the complete grammar, never a truncated prefix.
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

        // The WHOLE statement is retained, bounded by the ceiling above.
        // Retaining only a prefix was a bypass: padding a statement with more
        // leading whitespace than the window pushed `COMMIT`, `ROLLBACK`,
        // `START TRANSACTION`, query-form COPY, `COPY … FROM PROGRAM` and
        // server-file COPY out of view, and every one of them was accepted.
        $this->statement .= $char;
    }

    private function resetStatement(): void
    {
        $this->statement = '';
        $this->statementBytes = 0;
    }

    private function reset(): void
    {
        $this->inCopy = false;
        $this->copyCandidate = '';
        $this->copyLineViable = true;
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
                    $this->copyCandidate = '';
                    $this->copyLineViable = true;
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

        if (preg_match(self::ROLE_CHANGE, $normalized) === 1) {
            throw RestoreFailure::content(
                'نسخهٔ پایگاه‌داده نقش نشست را تغییر می‌دهد و پذیرفته نمی‌شود.',
                'role_change',
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
