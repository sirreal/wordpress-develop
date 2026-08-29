# REST Surface Notes

`ComponentFuzz\Surfaces\RestSurface` is intentionally stateless. It constructs
local `WP_REST_Request` and `WP_REST_Server` instances, registers dummy routes
directly on those local servers, and installs temporary filters for options that
would otherwise consult site state.

Covered invariants:

- Request method normalization and `is_method()` stability.
- Header canonicalization, content-type parsing, query/body/file parameter
  normalization, and repeated `get_params()` stability.
- Parameter precedence across JSON, body, query, URL, and defaults, including
  null-valued JSON params and `rest_route` removal when pretty permalinks are
  disabled.
- JSON body parsing for valid objects, malformed syntax, invalid UTF-8, and
  non-JSON content types.
- `rest_sanitize_value_from_schema()` idempotence on valid generated values and
  `rest_validate_value_from_schema()` rejection of enum, pattern, format, type,
  bounds, array, and object constraint violations.
- Route regex matching with exact named captures, malformed path no-route
  behavior, and malformed route regex handling without fatal errors.
- HEAD-to-GET fallback and explicit HEAD handler precedence.
- Permission callback strictness for `true`, `0`, `''`, `false`, `null`, and
  `WP_Error` return values.
