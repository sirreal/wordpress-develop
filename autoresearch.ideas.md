# Autoresearch Ideas Backlog

## High Priority (user-suggested)
- **Stack on_push/on_pop callbacks** — the HTML processor stack operations have push/pop callbacks. If these fire during tokenization (even indirectly), they could be significant overhead. Investigate whether any stack operations happen in the tag processor's read-only path, or whether these only apply to the HTML processor's tree-building.
- **Bookmark on_destroy callback** — bookmarks may have cleanup behavior that adds overhead. Check if any bookmark operations happen during pure tokenization.

## Medium Priority
- **Lazy token_length** — derive from bytes_already_parsed - token_starts_at instead of writing per token. Saves ~1M writes/pass. Requires changing all read sites.
- **Lazy is_closing_tag** — derive from html bytes. Saves 1 write/tag but adds cost to reads.
- **Deferred property writes with lazy flush** — save all non-essential writes, flush on demand. Big win for read-only, slight overhead for read-write. Protected properties can't be deferred.
- **Single boolean for modification check** — replace 2 array reads with 1 boolean read in hot loop.

## Low Priority / Speculative
- **Integer state constants** — replace string comparisons with int. API-breaking for protected parser_state.
- **Packed tag name properties** — combine tag_name_starts_at + tag_name_length into single int.
- **Static variable caching** — cache html/doc_length across calls.
