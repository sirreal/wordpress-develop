# Rough Idea

Work on active format reconstruction and reparenting support in the WP_HTML_Processor class.

## Key Points

- Note cases where the `bail()` method is used
- Rely on unit tests and the html5lib test suite
- Run tests with: `./vendor/bin/phpunit -c tests/phpunit/tests/html-api/phpunit.xml --group=html-api-html5lib-tests`
- Access the html5lib-tests/tree-construction tests to identify relevant tests

---

## Iteration 2: Attribute Handling and Noah's Ark Clause

Building on the basic reconstruct active formatting elements implementation, this iteration adds:

### 1. Attribute Handling for Reconstructed Elements
- Clone and preserve attributes when reconstructing formatting elements
- The spec requires creating elements "for the token for which the element entry was created" - including attributes
- Reconstructed elements should have the same attributes as the original formatting element

### 2. Noah's Ark of Three Clause
- Per the HTML5 spec, when adding a formatting element to the active formatting elements list, check for duplicates
- If there are already 3 entries with the same tag name AND the same attributes, remove the earliest one
- This prevents unbounded growth of identical formatting elements
- Reference: https://html.spec.whatwg.org/multipage/parsing.html#push-onto-the-list-of-active-formatting-elements
