# Unified Edits Array Design

## Problem

`WP_HTML_Template` currently uses three separate arrays to track compiled data:

- `$compiled` — placeholder offsets, keyed by name, with context
- `$text_normalizations` — `[start, length, normalized_text]` tuples
- `$attr_escapes` — `[start, length]` tuples (computed at render time)

These serve similar purposes (tracking spans to replace) but have different shapes and processing logic. The render method builds an intermediate `$updates` array from all three, sorts it, then applies replacements.

Additionally, `attr_escapes` defers computation to render time unnecessarily — the template string is immutable, so decode→re-encode can happen at compile time.

## Design

Replace all three arrays with two:

### `$edits` — unified edit list

A flat array of edit operations, naturally ordered by ascending offset (the processor walks the document linearly). Each entry is one of:

**Pre-computed replacement** (normalizations and escapes):
```php
['start' => 100, 'length' => 5, 'replacement' => '&amp;']
```

**Placeholder reference** (needs render-time lookup):
```php
['start' => 200, 'length' => 12, 'placeholder' => 'username', 'context' => 'text']
```

The `context` field is `'text'` or `'attribute'`, used to validate that templates aren't inserted into attribute values.

### `$placeholder_names` — validation index

A set of placeholder names for O(1) lookup during `bind()`:

```php
['username' => true, 'title' => true]
```

This replaces the current pattern of iterating `$compiled` to build a lookup on every `bind()` call.

## Compile-time changes

1. **Pre-compute attribute escapes**: Move the decode→re-encode logic from `render()` to `compile()`. Store only if the result differs from the original span (same redundancy check as text normalizations).

2. **Single array population**: As the processor encounters normalizations, escapes, or placeholders, append to `$edits` in document order.

3. **Build placeholder index**: When encountering a placeholder, also add its name to `$placeholder_names`.

## Render-time changes

The render loop becomes a single reverse iteration:

```php
foreach (array_reverse($this->edits) as $edit) {
    if (isset($edit['placeholder'])) {
        // Look up replacement value
        // Validate template-in-attribute
        // Escape and apply
    } else {
        // Pre-computed, apply directly
        $html = substr_replace($html, $edit['replacement'], $edit['start'], $edit['length']);
    }
}
```

No intermediate `$updates` array. No `usort()`.

## Bind-time changes

Validation uses `$placeholder_names` directly instead of building a lookup:

```php
// Check for missing keys
foreach ($this->placeholder_names as $name => $_) {
    // ... validate $name exists in $replacements
}

// Check for unused keys
foreach ($replacements as $key => $_) {
    // ... validate $key exists in $placeholder_names
}
```

## Invariants

- Offsets in `$edits` are strictly ascending (document order)
- No overlapping spans
- Entries have either `replacement` (pre-computed) or `placeholder` + `context` (render-time)
- Every name in an edit with `placeholder` key exists in `$placeholder_names`

## Trade-offs

**Gains:**
- Three properties → two properties
- Render: three foreach loops + sort → one reverse iteration
- Attribute escapes pre-computed (less render-time work)
- Redundant escapes filtered out (fewer edits when template is well-formed)
- Clearer mental model: "edits" are things to replace, "placeholder_names" is the validation index

**Costs:**
- Slightly more memory per placeholder entry (storing `context` per offset instead of once per name)
- Loses grouping by placeholder name (negligible — hash lookups are cheap)
