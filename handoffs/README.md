# Fuzzing / testing work lanes

Self-contained handoff documents, one per independent lane of work. Each
can be picked up by a separate agent or contributor with no shared
context beyond the document itself.

| Lane | Doc | Shape of work |
|------|-----|---------------|
| Extend the UTF-8 encoding fuzzer | [extend-encoding-fuzzer.md](extend-encoding-fuzzer.md) | Add targets to an existing, working fuzzer |
| WP_HTML_Decoder fuzzer | [html-decoder-fuzzer.md](html-decoder-fuzzer.md) | New independent fuzzer, Dom\HTMLDocument oracle |
| WP_Token_Map property tests | [token-map-properties.md](token-map-properties.md) | PHPUnit property tests against a naive reference |
| Legacy UTF-8 helper divergence survey | [legacy-utf8-divergence-survey.md](legacy-utf8-divergence-survey.md) | One-shot documented survey, no continuous fuzzing |

Background: `tools/encoding-fuzz/` (this branch, commit `3cc3e64765`)
is a working differential fuzzer for `wp_is_valid_utf8()` /
`wp_scrub_utf8()` and their pure-PHP fallbacks. ~570k cases have run
clean against five independent oracles. Its architecture (deterministic
`(seed, case)` generation, oracle battery, worker/runner/replay/minimize,
mutation-tested harness) is the reference pattern for the other lanes.
