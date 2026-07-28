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

    /** Statement-initial keywords that would break the outer transaction. */
    private const TRANSACTION_CONTROL = '/^(begin|commit|rollback|abort|end|start\s+transaction|prepare\s+transaction|savepoint)\b/i';

    /** Hard ceiling on one logical line, so a pathological file cannot blow up memory. */
    private const MAX_LINE_BYTES = 4_194_304;

    // Lexer state, carried across lines.
    private bool $inCopy = false;

    private bool $inSingle = false;

    private bool $singleEscapes = false;

    private bool $inDouble = false;

    private int $blockCommentDepth = 0;

    private ?string $dollarTag = null;

    private string $statement = '';

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
            while (($line = fgets($handle)) !== false) {
                if (strlen($line) > self::MAX_LINE_BYTES) {
                    throw RestoreFailure::content('ساختار نسخهٔ پایگاه‌داده نامعتبر است.', 'dump_line_too_long');
                }

                $line = rtrim($line, "\r\n");

                if ($this->inCopy) {
                    // Inside COPY data EVERYTHING is data until a line that is
                    // exactly `\.` — including text that looks like SQL or a
                    // meta-command.
                    if ($line === '\\.') {
                        $this->inCopy = false;
                        $this->statement = '';
                    }

                    continue;
                }

                $this->scanLine($line);
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

    private function reset(): void
    {
        $this->inCopy = false;
        $this->inSingle = false;
        $this->singleEscapes = false;
        $this->inDouble = false;
        $this->blockCommentDepth = 0;
        $this->dollarTag = null;
        $this->statement = '';
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
                if ($char === '"') {
                    if ($next === '"') {
                        $i += 2;

                        continue;
                    }
                    $this->inDouble = false;
                }
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
                $this->statement .= "'";
                $i++;

                continue;
            }

            if ($char === '"') {
                $this->inDouble = true;
                $this->statement .= '"';
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

                $this->statement = '';
                $i++;

                continue;
            }

            $this->statement .= $char;
            $i++;
        }

        // Statements can span lines; keep them separated by whitespace.
        $this->statement .= ' ';
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

    private function statementStartsCopyFromStdin(string $statement): bool
    {
        return preg_match('/^\s*COPY\b.*\bFROM\s+stdin\b/is', $statement) === 1;
    }
}
