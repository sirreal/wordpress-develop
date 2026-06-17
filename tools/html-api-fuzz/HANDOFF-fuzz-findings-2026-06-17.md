# HTML API Fuzz Findings Handoff, No HTML/BODY Attribute Hoisting

Date: 2026-06-17

This is the durable source-of-truth handoff for the 2026-06-15 fuzz run after discarding unsupported/oracle/resource-limit results and the known HTML/BODY/root-HTML attribute-hoisting family. The raw fuzz artifacts may be deleted after this file and the companion CSVs are preserved.

## Preserved Artifacts

- This handoff: `tools/html-api-fuzz/HANDOFF-fuzz-findings-2026-06-17.md`
- Full remaining signature inventory: `tools/html-api-fuzz/HANDOFF-fuzz-signatures-no-html-body-hoisting-2026-06-17.csv`
- Remaining normalized family inventory: `tools/html-api-fuzz/HANDOFF-fuzz-families-no-html-body-hoisting-2026-06-17.csv`
- Original analyzed run, if still present: `artifacts/html-api-fuzz/launch-20260615T204818398173Z`

## Scope And Filters

- Original run: 8,459,100 attempts across 5 lanes, PHP 8.5.7, oracle `php-dom`, commit `215047f36f9b7382a6d946b9fe1c4f7f62cfd08e`.
- Excluded statuses/classes: `unsupported`, `oracle-unsupported`, `oracle-tolerated`, and `resource-limit`.
- Also excluded: known HTML/BODY/root-HTML attribute-hoisting signatures, including synthetic `data-fuzz` mutation cases that manifested as root/body attribute hoisting.
- Remaining potential WordPress-owned signatures: 11,015; remaining rows: 13,626; normalized families: 1,342.

## Current Counts

| Failure class              | Signatures | Rows   |
| -------------------------- | ---------- | ------ |
| tree-mismatch              | 10,964     | 12,827 |
| mutation-tree-mismatch     | 26         | 26     |
| worker-failed              | 18         | 18     |
| normalize-invariant-failed | 3          | 702    |
| tag-invariant-failed       | 2          | 45     |
| timeout                    | 2          | 8      |

| Issue case                                    | Signatures | Rows  |
| --------------------------------------------- | ---------- | ----- |
| MathML / HTML integration tree construction   | 4,567      | 4,718 |
| SVG / foreign content tree construction       | 3,286      | 3,606 |
| template content tree construction            | 2,374      | 2,824 |
| RAWTEXT/RCDATA text-state handling            | 394        | 562   |
| generic element tree construction             | 97         | 321   |
| table/form/foster-parenting tree construction | 63         | 74    |
| generic text/comment placement                | 54         | 300   |
| select/option insertion mode                  | 54         | 58    |
| comment token placement                       | 50         | 181   |
| custom-element tree placement                 | 32         | 33    |
| root/body text placement                      | 19         | 176   |
| worker crash during processor normalization   | 17         | 17    |
| normalize output becomes unsupported          | 2          | 456   |
| tag processor seek/token-stream invariant     | 2          | 45    |
| processor timeout/hang candidate              | 2          | 8     |
| normalize idempotence                         | 1          | 246   |
| processor crash in step_in_body stack walk    | 1          | 1     |

## Reproduction Pattern

Each case below contains an exact base64 input. To reproduce a case, run the shell block in that case from the repository root. The command invokes `worker.php` directly with the original mode/profile/seed metadata and writes fresh artifacts to `/tmp/html-api-fuzz-handoff/<case-id>`.

Use `--dom-oracle lexbor-source` as a second pass for tree mismatches before filing a browser/oracle-sensitive bug. The invariant, crash, and timeout cases do not require an oracle to be considered actionable.

## Likely Code Entry Points

- Seek/token-stream invariant: `tools/html-api-fuzz/lib/TagInvariants.php:82`.
- Normalize idempotence and unsupported normalized output: `tools/html-api-fuzz/lib/TagInvariants.php:120` and `tools/html-api-fuzz/lib/TagInvariants.php:240`.
- `step_in_body()` crash/loop suspects: `src/wp-includes/html-api/class-wp-html-processor.php:2777`, `src/wp-includes/html-api/class-wp-html-processor.php:2792`, and recursive ignored end-tag handling at `src/wp-includes/html-api/class-wp-html-processor.php:2885`.

## Cases
### case-01: processor crash in step_in_body stack walk

- Aggregate scope: 1 signatures, 1 rows.
- Representative signature: `dc100200fc40`; representative signature rows: 1.
- Failure class / shape: `worker-failed` / `worker-failed`.
- Seed/profile/mode/payload/source: `6258159` / `foreign-content` / `fragment-body` / `mostly-valid` / `generated`.
- Input length/SHA1: 4352 bytes / `0acdddef575ca9645bf6b7a16fc23313871077a8`.
- Human preview: `"<math :colon=1#q_&V. &0  disabled = \"C'D\u00006'&&#00000000;\"\nstyle=ssY_i&_L_-><mi\fdata.thing=\u0928\u092e\u0938\u094d\u0924\u0947&not\r\ndata-Foo\f= '\u05e2\u05d1\u05e8\u05d9\u05ea&centerdo;'\thref=\"\ud83d\ude42\">\u0928\u092e\u0938\u094d\u0924\u0947</m"`.

Observed behavior:
- Worker status: `worker-failed` / `worker-failed`.
- Failure message/stack summary: `tml-api<path> WP_HTML_Processor::is_special(NULL) | #<n> <path> WP_HTML_Processor->step_in_body() | #<n> <path> WP_HTML_Processor->step() | #<n> <path> WP_HTML_Processor->next_visitable_token() | #<n> <path> WP_HTML_Processor->next_token() | #<n> <path> WP_HTML_Processor->serialize() | #<n> <path> WP_HTML_Processor::normalize('<math :colon=<n>#...') | #<n> <path> HtmlApiFuzz\TagInvariants::normalize_html('<math :colon=<n>#...', 'fragment-body', 'body') | #<n> <path> HtmlApiFuzz\TagInvariants::check_normalize_idempotence('<math :colon=<n>#...', 'fragment-body', 'body') | #<n> <path> HtmlApiFuzz\TagInvariants::check('<math :colon=<n>#...', Array, 'fragment-body', 'body') | #<n> <path> HtmlApiFuzz\Worker::evaluate_input('<math :colon=<n>#...', <n>, 'foreign-content', 'fragment-body', 'mostly-valid', 'body', Array, 'generated', Array, false, Object(HtmlApiFuzz\OracleRenderer)) | #<n> <path> HtmlApiFuzz\Worker::run(Array) | #<n> {main}`.

Expected behavior:
- The processor should not throw warnings/fatals during normalize/serialize; it should either produce output or return a normal unsupported/failure result.

Top normalized families in this case:
| Class         | Shape         | Mode          | Detail | Sigs | Rows |
| ------------- | ------------- | ------------- | ------ | ---- | ---- |
| worker-failed | worker-failed | fragment-body |  =>    | 1    | 1    |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-01
B64=$(tr -d '\n' <<'B64'
PG1hdGggOmNvbG9uPTEjcV8mVi4gJjAgIGRpc2FibGVkID0gIkMnRAA2JyYmIzAwMDAwMDAwOyIK
c3R5bGU9c3NZX2kmX0xfLT48bWkMZGF0YS50aGluZz3gpKjgpK7gpLjgpY3gpKTgpYcmbm90DQpk
YXRhLUZvbww9ICfXoteR16jXmdeqJmNlbnRlcmRvOycJaHJlZj0i8J+ZgiI+4KSo4KSu4KS44KWN
4KSk4KWHPC9taT48YW5ub3RhdGlvbi14bWwgZW5jb2Rpbmc9InRleHQvaHRtbCI+PGgyIGRhdGEt
Rm9vID0JJw0Ke0w6NSc+Zzs+OW4pJiNYRkZGRDsnLnAibj5VU2lueDNQRm1KTmxyPHJlZT42dHkz
TCdxZCk78J+ZgiYjMDAwMDAwMDA2MDtILjw+ek5bPn0xZSc8aUhdJ/CfmYJLe3U3ZWdkbSYjMDA2
NTs8IS0tJ2I9QyZKdD0yQwA4PHgtLT48PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDpMM8OpZyJIAGE5
eCYjeDExMDAwMDsmQzp5WDxjJmc+a2p9PEVK6ZuqJk5vdEVxdWFsVGlsZGU+b0xWIDhfOXYi16LX
kdeo15nXqs6y8J+ZgiYjeEQ4MDA7CT17aCAmIzEzOzwvaDI+PGN1c3RvbS1RaQpAY2xpY2sMPSAi
PT4nR0F7MCciIAkgOmNvbG9uDD0gYWFhYWFhYWFhYSYjWDAwMDAwMDAzQzsJW2RhdGEteF09NTFn
eHVaX2UzdlI2I1FGX25vUl9vMk9fRDYmI1hhMDs+JiN4MDAwMDAwMGEwOzwvY3VzdG9tLVFpPjxz
dmcgCSBkaXNhYmxlZAw9ICfOsiYjMDsnIEEtWWJrdFM7cGEtbWhabTRqOShBSVBLWC0pQy1QMHpY
TTlucEMtTi5FcDVMSS1CTWkoTi1kLUNFclZjLS03bU8tbylJbkRBTzh4LTMtIzQtalFPOS1WaUIm
PV9kZX1pJk5vdEVxdWFsVGlsZGUgdmlld0JveD0iMCAwIDEwIDEwIj48Zz48dGl0bGU+HzVTWUgv
QylnRn1QOTsmI1gwMEE5O0s0PkI+WGYsO2FwS2g8UD1TUzI1MWExMyDgpKjgpK7gpLjgpY3gpKTg
pYc8L3RpdGxlPjxmb3JlaWdub2JqZWN0Pk9yUz5udGQsMEN1IGxjbEImbzk6Yyk+QVt5NX1zMzxj
bnkwSy9BTywiTiBoYmJlcicvTVY1RntnJ2l1UENYfSx2fXVkUmFUc3dneWx3NWV2JiZaRzwwVEom
TQA6KFlwPFZQPCxDIj0vPSYjMDAwMDAwMzg7LzFUJk41M/CfmYI8L2ZvcmVpZ25vYmplY3Q+PC9z
dmc+PC9hbm5vdGF0aW9uLXhtbD48L21hdGg+PG9wdGlvbgxocmVmPc6yDQo6Y29sb24NCnhsaW5r
OmhyZWYgPQki4KSo4KSu4KS44KWN4KSk4KWHJm5vdGluIj48c3ZnDHRpdGxlPc6yDHMtOj0iLE1q
cyYjNjA7IgppZD0iQnliUz09V2N0Jks+W3dyKENjeWRIYjU9ZXgmVD55WTFzWWZnbEE2ZCMwYy04
MlJUfWgwJlU8RDF2PUswWXFIPik4MT5lNWxpUD0vbHptMzkpKWk8bUkiIDpjb2xvbiA9CScsJnVb
Zjw6ZzwnIHZpZXdCb3g9IjAgMCAxMCAxMCI+PGc+PHRpdGxlPlt6PlcmZGl2aWRlb250aW1lczsy
Jk5vU3VjaEVudGl0eembqjwvdGl0bGU+PGZvcmVpZ25PYmplY3Q+PCEtLTw8PDw8PDw8PDwtLT48
L2ZvcmVpZ25PYmplY3Q+YXFKJwEtJzw3PHRlbXBsYXRlIAkgeG1sOnNwYWNlID0JIiIKZGF0YS0t
eD0zd3ZfQTBdJiN4MDAwM2M7Pjx4bXAgZGF0YS1Gb289Imw8PD1XOyx3c0hILzs2QUNNZydQPUs8
PntWVmVQI1o9MEpdPVc9Oj4nRCcmVmMnUkdmIgx4bWw6bGFuZyA9CSI2RDw0ajw4PD1DUCxqPUo8
ZCcwZVpaPSZDWCZiL29nW2FwZEItJnk7N1dbd10nVHJEPjdNPjxVOT4yNENITD1vaFJXRSZRPjom
Vyd9WC90a300XzwmWz1qLCI+dmJELzY7I0wmJiYmJiYmJiYmJiYmJiYmJiYmJiY8L3htcD48bWF0
aA0KW2RhdGEteF0gPSAiPDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8
PDw8PDwmYm9ndXM7Ij48bWk+4KSo4KSu4KS44KWN4KSk4KWHJiM2NTvXoteR16jXmdeqJmFtcDs8
L21pPjxhbm5vdGF0aW9uLXhtbCBFTkNPRElORz0iVEVYVC9IVE1MIj48IS0tMCAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAtLT5UM0d2Ing+THEnJkNPUFk7MD49ME1n
TOmbqmRpPDA9cUF5I01xUFUndlBqIkoiZEpQJiJdbydMYTdFajM8JiZpemdQeU9WJilhNkI+PCZM
al1ReDBzPVhELj5hPldqQm9qaj53PDV5a2h1Jj1vYm8+bChEbDwvYW5ub3RhdGlvbi14bWw+PGN1
c3RvbS1HbUkKYXJpYS1sYWJlbD7Zhdix2K3YqNinOlNuSiYjWDNFO3B2Jm5vdGlu4KSo4KSu4KS4
4KWN4KSk4KWHNztmPGRddT0mUVVPVDs2J1ZDcWpnKFc9Szw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8
PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8THRDczNQDSNFUz0mYXBvcztn8J+ZgvCfmYJ5
LHssPG05MCMnDQpfakFEJkFNUOCkqOCkruCkuOCljeCkpOClh2pjRiZDR1MmbXF7JiN4OyZxdW90
HyYyT2VYYTw+PXRKUiZub3Q8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw9cXUA
Qlk+JkNPUFk7zrI8L2N1c3RvbS1HbUk+6Zuq16LXkdeo15nXqiZndDs8ZUwzUTg+OzMwZ0RFc0hk
I2NzImltJ1dKdFM9az49KHJrVylTMzF3Pj5JPnY9PENNOEQ+YXBvdHE8dl9JInN6Vz1ORU4vJzk2
endhY1EzeD5vRUI8PWh3OT4zbF9CJnY+QnInPHsnJnBEJix9UEktQjxsfU14cnpUclhDUyd7bUk8
c3ZnIGRhdGEtRm9vID0gIkRET2E9PnttKDtEZCZKPCINCjA6Zlh9ZDZoDD0g8J+ZgiBzcmMgPSAi
UDxJXz1ZS11CVVNMQXRXbnZzViZSa0d4SDdSW0VqWjFmZ2MmcjtKPHpBLSw8PlsgZEo3JmN2SGlw
RkwmRmw0ayg2PSMiIHZpZXdCb3g9IjAgMCAxMCAxMCI+PGc+PHRpdGxlPj48PDw8PDw8PDw8PDw8
PDw8PDw8PDw8L3RpdGxlPjxmb3JlaWduT2JqZWN0Pj4+NwA2PGkmQ09QWWFhYWFhYWFhYWFhYWFh
YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhOTJEJngnXzw+aXllzrImTFQ7ICAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgINei15HXqNeZ16rDqTw8PDw8PDw8PDw8PDw8
PDw8PDw8PDw8PDw8PDw8PDw8PCc+JwA0d08+emc+JiMxMjg1Nzg74KSo4KSu4KS44KWN4KSk4KWH
OWhiaVVdWVU9PD5CJjs8OlBUbW88J0w+L3Z1bS4pPTtdZlM5WS9fNENKU2ooWzhKe2Q0YnU9TzY8
PC9mb3JlaWduT2JqZWN0PjwvdGVtcGxhdGU+PCFET0NUWVBFIGh0bWwgIl9rUktjNTk4Ij48IS0t
YTwhLS1iLS1jLS0+PHNvdXJjZSB4bWw6bGFuZz0iJiYnNDInOCZCNFc2czwyIiAgZGF0YS14CT0K
IlQwOUFDZzwzcTssLyYgUDN2PlA8ZjVYVCdFXVUyd1o8QnInOT03T2otTzhrfWwjWDw8J2ZSWTIj
bE4nVyg3bjxRPjdFPil9PTZsNEd5W0c8PG1TNGx7fSBbWT1rPCI+PG1ldGEgCSBjbGFzcww9ICI3
LSYjMDAwMDAwMzQ7Ig0KQGNsaWNrPjxiciBocmVmLz48bGkgCSBocmVmID0JJ1U+cDYnIEhyZWY9
YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhJkFFbGlnPjwh
LS0tPjxtYXRoIAkgZGlzYWJsZWQ9JyMnCm9DbVdZbltSVz3Zhdix2K3YqNinICBzcmM+PG1pCXl2
LFFiCT0KJ+CkqOCkruCkuOCljeCkpOClhycgY2hlY2tlZCA9CSJxVSBudkJ7M1QzJ1VyV0FuVUds
UUp5cDU1XU86dW8nTkImSS5MTTE6dW5QTjtdQ2g+ZSYjNjU1MzM7IiBzcmMgCSBjbGFzcz0iOEQ1
ACYwNCI+Nik7ZXd3KSYjeEZGRmQ7bUZBAG9QWTw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDJEc2w+
am4yXSZuMCZuZ0U8L21pPjxhbm5vdGF0aW9uLXhtbD4yLDhaZkg6KGVwPSZBSXtBTD49Oz1USzx4
J3E9bGlGb3YvPEoiL3VUWHJQSnNCJiN4MTEwMDAwO1V0aUEmW0R4OkI7UzU6Ok09MHo+dTZ9R3tp
aj1KJyYve0g8JjVBV1dzO0YpMlpFPD1OWjxKbyYmeGE5SVotLmVpT1MwWmUm8J+ZgjwhLS0mJiYm
LS0+KUs2cDw3SC05RyYuVXg7RFkgNjcnanR9OUNqVFcmOXZZeWVUWmpCYSY1ZGopeEl1W2t2J29d
UCZIMCd0U3JmPFcyLQBWenoBOGZvIjwvYW5ub3RhdGlvbi14bWw+PHByZSBkaXNhYmxlZCA9ICdx
YV8nPjxvcHRpb24gAUMoaSMMPSAiw6kiIGFsdD55Pl9IPj4xSFJvZjdPVidIS2VnYTQmWns9SXQ8
NW56KU12KTxzQXB1WV8gd1FTMD13V01UTz1p8J+Zgg1PbT158J+ZgjwhLS01YzIjRg1DelpFViI8
Wj1IOjdNWnFKPEk3OXsmbmYuLGouWC05JjVyPjQ7LUlvJlFZWllaN1E0Wz1Bc1V0fTluYWFhYWFh
YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYS0tPiw+LShMM0M8Jm5vdGluO/CfmYJIYk47
UkVPc2U816LXkdeo15nXqiZxdW90OwkiQmtXPC9vcHRpb24+PC9wcmU+PHN2ZyBycHN3fTdoc355
JD0iSF9SAG8wOyZMVDsiIGNsYXNzID0gJ0kpPTx0YWc+JyB2aWV3Qm94PSIwIDAgMTAgMTAiPjxn
Pjx0aXRsZT7Zhdix2K3YqNinSk89eyY8MW/gpKjgpK7gpLjgpY3gpKTgpYfZhdix2K3YqNinICAg
ICAgICAgICAgICAgICAgICAgICZub3RpbjwvdGl0bGU+PGZvcmVpZ25PYmplY1Q+LD0mVk82TCZO
b3RFcXVhbFRpbGRlcGlOAFF4ayYjWGZGZkQ7JiIxIyc+OS86SF86cClneyYuYmxiLilufT5JJjwh
LS04cy0tPjwhLS1CZzxFPC09PjwnazZqdD5scTd7QTVCLS0+PC9mb3JlaWduT2JqZWNUPjwhW0NE
QVRBW052ZFo3XzVdXT48L3N2Zz4=
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 6258159 --profile foreign-content --mode fragment-body --payload-policy mostly-valid --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-01
```

### case-02: worker crash during processor normalization

- Aggregate scope: 17 signatures, 17 rows.
- Representative signature: `80a94cdada94`; representative signature rows: 1.
- Failure class / shape: `worker-failed` / `worker-failed`.
- Seed/profile/mode/payload/source: `144536` / `foreign-content` / `full-document` / `valid-utf8` / `generated`.
- Input length/SHA1: 2780 bytes / `f5ed2e25cf43e1038016dd4de62388a010a61f87`.
- Human preview: `"<html\txml:lang><title>\u05e2\u05d1\u05e8\u05d9\u05ea</title><meta><body><mark><math><mi>-sjB=K 5Qj\u03b2</mi><annotation-xml ENCODING=\"TEXT/HTML\">\u05e2\u05d1\u05e8\u05d9\u05ea>{WA=>ocy&&>\u00e9Bj>m[PhKeYpxb=c</annotation-xml><t"`.

Observed behavior:
- Worker status: `worker-failed` / `worker-failed`.
- Failure message/stack summary: `<path> HtmlApiFuzz\TagInvariants::{closure:HtmlApiFuzz\TagInvariants::check_normalize_idempotence():<n>}(<n>, 'Attempt to read...', '<path> <n>) | #<n> <path> WP_HTML_Processor->step_in_body() | #<n> <path> WP_HTML_Processor->step() | #<n> <path> WP_HTML_Processor->next_visitable_token() | #<n> <path> WP_HTML_Processor->next_token() | #<n> <path> WP_HTML_Processor->serialize() | #<n> <path> HtmlApiFuzz\TagInvariants::normalize_html('<html\txml:lang>...', 'full-document', 'body') | #<n> <path> HtmlApiFuzz\TagInvariants::check_normalize_idempotence('<html\txml:lang>...', 'full-document', 'body') | #<n> <path> HtmlApiFuzz\TagInvariants::check('<html\txml:lang>...', Array, 'full-document', 'body') | #<n> <path> HtmlApiFuzz\Worker::evaluate_input('<html\txml:lang>...', <n>, 'foreign-content', 'full-document', 'valid-utf<n>', 'body', Array, 'generated', Array, false, Object(HtmlApiFuzz\OracleRenderer)) | #<n> <path> HtmlApiFuzz\Worker::run(Array) | #<n> {main}`.

Expected behavior:
- The processor should not throw warnings/fatals during normalize/serialize; it should either produce output or return a normal unsupported/failure result.

Top normalized families in this case:
| Class         | Shape         | Mode          | Detail | Sigs | Rows |
| ------------- | ------------- | ------------- | ------ | ---- | ---- |
| worker-failed | worker-failed | fragment-body |  =>    | 13   | 13   |
| worker-failed | worker-failed | full-document |  =>    | 4    | 4    |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-02
B64=$(tr -d '\n' <<'B64'
PGh0bWwJeG1sOmxhbmc+PHRpdGxlPtei15HXqNeZ16o8L3RpdGxlPjxtZXRhPjxib2R5PjxtYXJr
PjxtYXRoPjxtaT4tc2pCPUsgNVFqzrI8L21pPjxhbm5vdGF0aW9uLXhtbCBFTkNPRElORz0iVEVY
VC9IVE1MIj7XoteR16jXmdeqPntXQT0+b2N5JiY+w6lCaj5tW1BoS2VZcHhiPWM8L2Fubm90YXRp
b24teG1sPjx0ZW1wbGF0ZQl0aXRsZSA9ICIga2J0PCI+PGJ1dHRvbg0KZGF0YS1Gb28MPSAncycg
ZGF0YS14PSLZhdix2K3YqNinIiAgZGF0YS1Gb28gPSAnJmE2L1tPJwlocmVmPSfgpKjgpK7gpLjg
pY3gpKTgpYc8Jz48IS0t4KSo4KSu4KS44KWN4KSk4KWH6ZuqLS0+PCEtLV1ZIyJyOzI9YTY8azpL
JzVWPCdvQT09Pj5ZU0JdX2hZVUl6NUgnJk4wbH1kbXU8SWcodTJ3eiwwSWhMPSY8dkltPn1hUURC
X0UpJlhnfVktIzk+Vm1sa0FffTw9LS0+PC9idXR0b24+PG1hdGggeG1sOmxhbmc9Ikp9cmFIIF9h
SXQmJzo1QnJdNyBUPixMY3NsaUxNbUomQVI8Micyc29SMH1MLnEyNERhPFhqJz4uRjd1JiM2Mjsi
CmRhdGEteCA9ICdsPVFbJkZfbCZyJz48bWkgc3JjCm9uOmNsaWNrID0JIuCkqOCkruCkuOCljeCk
pOClhyI+MSxMNngNCncnaVMmPXMmL3g4PWQ9PkNNWGt3cjZ7aCI8LCM9e1VhKSw5TFJqOlhubnpO
Xzh2VVpfI1E+cSkmIzM0O088YUhiPDwmTm90RXF1YWxUaWxkZSYmbmNhcm9uOzwvbWk+PGFubm90
YXRpb24teG1sIGVuY29kaW5nPSJ0ZXh0L2h0bWwiPlZ2O3dfVy5PJiMxNjk7ICAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAmbmJzcDvZhdix2K3YqNin
Jm5vdGlu16LXkdeo15nXqgkmOVZINSJkbzRCTXVkLlFVIzFzJ08gJk0tUjUmNT5odVdqJj1jYSxP
JzVRV2hzPEU1PSIvSj5lcmFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFh
YWFhYWFhYWFhYWFhYWFhYWFhN3hMMicmbncmaSM9T1VaJnZBQT444KSo4KSu4KS44KWN4KSk4KWH
Pjw+PsOpJiMxNjk7JzxLAFBXJsOpPDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8
PDw8PDw8PDw8PDw8PDw8PDwmIzM4Oz1iQV9fRGxNcGRqSFsidVtRR1FwWmwtRl80RDw8L2Fubm90
YXRpb24teG1sPjx1bD48bGkgY2xhc3MJPQrZhdix2K3YqNinCWNoZWNrZWQgPQki2YXYsdit2KjY
pyIKYWFhYWFhYWFhYWFhYWFhYWEgPQlKRzJCO3ovOV8ycjo0JmZ3LzV3X2EtfU5fLTU2akhVRXMm
WmZualdfT19nOkxaX2V2O19mMFpZX09fQl9XcXhaOWtNJnsmeE9fJlF6I190LiAJIGRpc2FibGVk
DD0gJyYmJiYmJiYmJiYmPic+4KSo4KSu4KS44KWN4KSk4KWHNWY9UT5jJklnOzIsQjtUPgo8Qz5M
zrI8bGkgQGNsaWNrPSI+PG9FSz4oNHgmI1gyNjsiIEBjbGljaz0izrIiPix4cHXXoteR16jXmdeq
PG1hdGg+PGc+cFtFPGNlbnRlcj5XMEJjdXFqSyY8WVdhSyB6ajwhLS1wRFpdUHonYic8WWUzKVd6
M104PEEsdz5DTFl2PHp4PGJ3bG8gRD0sPU1HYlo9KC0tPjwvY2VudGVyPjwvdGVtcGxhdGU+PGR0
IGRhdGEtZW1wdHkgPSAmQ09QWQo3Mko4XnBBPV9GXzFtJiN4MDAwMUY2NDI7DQp4bWxuczp4bGlu
ayA9CSdBPmVpIn19ICNBIlU+WURNclM+V1o+TTxlZyxhKXI8ZmZZR3I8Il9OM3lhPHRSPGhvVC92
Ii0zZTAwPX1nYzB2WCJoImYmbm90aW4nPjxvbCBocmVmPembqiZyZWc7ICBocmVmCWRhdGEteCA9
CSLXoteR16jXmdeqJiMxMjg1Nzg7Ij48UjkmI3gwMDAwMDAwMDNDO1kiZQAseyYMNGk+djwhLS1n
RWdQdFYmJi86R0x1MSYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJi0tPiY8zrIn
YURhOSI8Pnk5UEpXXV1jdjZuIy85dkg9PCw1c1Q9bi50PWkmKTstb0NXRFNpdFs+TGV5d1UnbGc0
O1EnbW5CRVUpWTE9PmMmazxpaGopUFJvLzw8OE8mOw0Ke0M0bD1zdSdiPEU7eDV6JjAmIzA7PC9v
bD48YnV0dG9uCXE+dy4mIzAwMDAwNjI7bVo9JiN4RkZmRDtCPXVrbSMmYXBvc3otWjsmdjlES2d5
OFZRQTdHY3YwMllMLihNW2w9SV8+eHhkN0IoaCguUkYjbyd0TD4oUFo3L0RxUD1lTz54RGNNQjth
TT1yeiJyeFU3TVdDdSY4ZjwvYnV0dG9uPjxzdmcgIGNoZWNrZWQ916LXkdeo15nXqiBjbGFzcz0i
Nkc1OTU8OyZxdW90OyIgdmlld0JveD0iMCAwIDEwIDEwIj48Zz48dGl0bGU+IzxhYi9KNnlhVXYg
Nl8Ae3Y+w6k8L3RpdGxlPjxmb3JlaWduT2JqZWN0PjwhLS1leVlLaFF4ND5LUj5ZdmEvbVFqI1VJ
PVBnPDk5PT08R2VUa2ZhX1s+LD1rJkxOLGonWy55JndMMTY9TVU+QWFwcDN6OEs1Ryc1X2ZoJ1JB
UjwtLT48L2ZvcmVpZ25PYmplY3Q+PC9zdmc+PHN2Zz48Zz5Se3Q8ZGl2PjwhLS1RPnV1VidfMU4u
LS0+6ZuqPCEtLVdFTQAsN2MtLT45KHc9U0plaD15IDRzS1NmRlE9VWZOIEYmLT42YnRzQkdNIEpL
J2ggICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgUC9RaEUtOy0nJyMmI3gwMDNlO1da
Jj0mPlFhUDU+VlA9dCg7bGxKWS1VTjMtOXhqNSN4PkRzSWpnICYtcCZub3RpdDsiI2kgICAgICAg
ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICYjWDAwMDFGNjQyO8OpccOp4KSo
4KSu4KS44KWN4KSk4KWHJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJjxG
ZCxzPVE9IiZub3RpdDs8IS0tUjlyeilKLS0+PGR0Ptei15HXqNeZ16p9KT0AQjxTJmJvZ3VzO1Q9
OFNrYj47VD1QJkFFbGlnOzwveC13aWRnZXQ+PC9kdD48c3Ryb25nICBjaGVja2VkID0gIiM7M29T
KWkmI3gwMDAwMEEwOyIMeG1sOmxhbmc9J1ZCMwBfOUQmI3gwMGE5Oyc+PCFET0NUWVBFIGh0bWw+
PCEtLWNvbW1lbnQtLT48L3N0cm9uZz48L21hcms+PC9ib2R5PjwvaHRtbD4=
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 144536 --profile foreign-content --mode full-document --payload-policy valid-utf8 --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-02
```

### case-03: processor timeout/hang candidate

- Aggregate scope: 2 signatures, 8 rows.
- Representative signature: `2b23994deaf6`; representative signature rows: 2.
- Failure class / shape: `timeout` / `timeout`.
- Seed/profile/mode/payload/source: `6016228` / `balanced` / `full-document` / `valid-utf8` / `generated`.
- Input length/SHA1: 11779 bytes / `0f51825e994fa79acf02ee69e5ce6a73a9a50a37`.
- Human preview: `"<!DOCTYPE html><html\tsrc=_CnO\r\nSRC=_/_I \u03b2\t=\n\"pKDT#oc666\"  data-Foo><body><form data-x = 4MYxBeNjGB_d&LDC_]Y(qZrRvDc_v5_6_z]x&p},_B,j_ewJl)fx_GE_ xlink:href\f= \"K9&{6O\"\thref disable"`.

Observed behavior:
- Worker status: `timeout` / `timeout`.
- Failure message/stack summary: ``.

Expected behavior:
- The worker should complete within the configured timeout or fail in a bounded, classified way without hanging.

Top normalized families in this case:
| Class   | Shape   | Mode          | Detail | Sigs | Rows |
| ------- | ------- | ------------- | ------ | ---- | ---- |
| timeout | timeout | fragment-body |  =>    | 1    | 6    |
| timeout | timeout | full-document |  =>    | 1    | 2    |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-03
B64=$(tr -d '\n' <<'B64'
PCFET0NUWVBFIGh0bWw+PGh0bWwJc3JjPV9Dbk8NClNSQz1fL19JIM6yCT0KInBLRFQjb2M2NjYi
ICBkYXRhLUZvbz48Ym9keT48Zm9ybSBkYXRhLXggPSA0TVl4QmVOakdCX2QmTERDX11ZKHFaclJ2
RGNfdjVfNl96XXgmcH0sX0Isal9ld0psKWZ4X0dFXyB4bGluazpocmVmDD0gIks5Jns2TyIJaHJl
ZiBkaXNhYmxlZD0nejwwY3V5LD4yPic+PHAgCSDDqT0n6ZuqPicgUD7DqTw8PDw8PDw8PDw8PDw8
PDw8PDw8PDw8PDw8PDw8cCBkYXRhLUZvbyB4bWw6bGFuZwzwn5mCIGNsYXNzPSI4VWY4JnF1b3Q7
Ij4udDAxPWZyPHANCmFsdCA9CSJ9bzEnfW9fbz4iIGRpc2FibGVkICBkYXRhLXggPSAiUzZJKD4n
VnlPJ0FMYkw9aEQvJicpQjlELE05eEc3J1pRQXc+Nkh0Jkw5YT4+TjIuTzUsWlZ7emxLYkFjckk8
L31dPTtdNSc9ciI+2YXYsdit2KjYp8OpPHAKY2xhc3MgPQlfeC9fIzhfNltfczooMFJNNm86eXJL
MnNfW0xoJlVfQl9uJiN4MDAwMDAyMjsKw6k9Iil4b1RzNTQiPumbqlg4dvCfmYImIzY1Ox9CPlpt
MTw9PHAgc3JjCT0KIkdLUABrZzoiPic8IW5vdC1hLWNvbW1lbnQ+PHN2ZyBfIF89ItmF2LHYrdio
2KcmI1gwMDAwMDAwMEE5OyIJZGF0YS1Gb289X3ZfJm5vdDsKZGF0YS14PSfOsicgdmlld0JveD0i
MCAwIDEwIDEwIj48Zz48dGl0bGU+4KSo4KSu4KS44KWN4KSk4KWHZWMwAE1maiZhcG9zRjJdPC90
aXRsZT48Zm9yZWlnbk9iamVjdD48M20+QSYjMDAzODvpm6omXT4mR1Q78J+Zgj0jckZzcydHdGhR
InQySydOPmJTUjg+TFo4NjZJXVY+cUdTJmNWW0IiXXEyWVhMdDw9e0h7UicnPWc0RWJxT10mMjMm
MFg7Pm8oMTw9Pko5WVdPXyYmcX1HQ3JZRC4yeFkoRXROcXdmXVVLMkdYeDw1JzZ4b3gyPUp0fWUw
PllOPDUnfVFSIs6yw6nwn5mCJm5ndDvXoteR16jXmdeqPVE9PS9wJk5vdEVxdWFsVGlsZGU7PCEt
LVlxUno+cXFsQTV4TmEsV19URTZxLS0+PCEtLXlRPi1Gez1yN1p9SHk+UkQ8QnZYXyYtPHQ9SUlO
OFdFUFkmbHQ7Sm9VaSh4KF1oT0o+akx9ViZ4dTY8UkhNPTlqWFJEZTc8Y1BSV2pbaT1jc3NFLG1Y
8J+ZguCkqOCkruCkuOCljeCkpOClh11CPUlkIyZQPUxMLyc9RTVwYjt4Jzh4KFswVV9ucHluLDxf
JntLPk1bI2c2PHh9akM1OmY2c1BWRkxzZm48dHFiJyY+Ly89NUYmOmhTLGlERkQudzIiTG4+TnU9
MCYuLS0+PC9mb3JlaWduT2JqZWN0PjxzZWN0aW9uDFtkYXRhLXhdPSfgpKjgpK7gpLjgpY3gpKTg
pYcnIFtkYXRhLXhdPXZqNUhfUF9XV0RwY1lnTV90cGRyY1s4WzNrdCNsbl1EQV9jZWdwMkdPX2Jx
XwlPJXQ6SklZdmxUfH0wbD0iIg0KaHJlZiA9IEtKSGhfWmZSanF0NmNfYS5fJmFDVS0jT0FfQl8u
d196d19hNTRfT3tpMXNmW0hfYyN0S18wdS5xJnBJaTdaeXZfXTp4MmxvYWFXbGJfR3dfO3ViX25w
JmNlbnRlcmRvdDs+L3tjPWtMIlI0XyguJjcxTkZZSG5WfUMxJi05JjEmPiZBRWxpZzvXoteR16jX
mdeqPC9zZWN0aW9uPjx0ZW1wbGF0ZT48dGVtcGxhdGUgIGRpc2FibGVkID0J16LXkdeo15nXqiZk
aXZpZGVvbnRpbWVzOwlkaXNhYmxlZD5BPC5SOT46w6nDqTwhLS3OsgEmaTFPLS0+e2I4J1dSdXE3
PjtRc1BTVE9wPiY9cExBVjlWTzwmbUp7cTYyL0ZXNiY8YT1XZDciPD1fRSZ5KSNSPj0mSm1IYVN2
cSdwRk1CdWF6SGs0UV06bjxVQXZSVl1CWiw1W1A0IiZGIklYNlgiPEhjNXA+eD5Wb/CfmYLpm6pv
VC8we0RqInRGZCBsYUlHRmwnPjA+Zz4+JiN4MDBhOTs8L3RlbXBsYXRlPjw8PDw8PDw8PDw8PDw8
PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8bWFyaw0Kc3R5bGU9UDo7
Jl9xUXNOSSYjWDFGNjQyOz5lZUstJiJOYyY8PC0vSz41Oj1QPDBOeGhUUDltY19OVU9oelngpKjg
pK7gpLjgpY3gpKTgpYc3RWE2IyZWeUROWnE9PjwvbWFyaz48c3ZnIHZpZXdCb3g9IjAgMCAxMCAx
MCI+PGc+PHRpdGxlPtei15HXqNeZ16omI1gxRjY0Mjs8L3RpdGxlPjxGT1JFSUdOT0JKRUNUPiAg
ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICDwn5mCJiN4M0M7Wng8PDw8PDw8PDw8
PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDwmbmNhcm9uO+mbqiYjNjA74KSo4KSu
4KS44KWN4KSk4KWHO1lVb0tUJm0xRHNwImRLQnAmRD1JOEd5ayJWMVo9OlsjQSxvImY+PndMPlZB
WyZIMDkyIHc0QiY4PkVFeTc1T2I+IjFoUWN6a0QmIzEyODU3ODs+R2ZMe0c1Ptei15HXqNeZ16pG
Pix2diY9SXpLL0opbE9xPFtyZFsoLTBOWCZnJ1dIR1Y+e3VsQi95N1dnUD5TW2o2ZHk3JntdUj4j
ZnVYdDx2byd6UyYtQng+cSZWQjppS0ggRSx6OD4gW0J7X1Z6JiM2NTvDqTsmTFQ7PC9GT1JFSUdO
T0JKRUNUPjwvc3ZnPjx4bXAgaWQgPSAnQ2tlT1pLND0mJyBzdHlsZT0iPDA+VXNFW11oLWhqMmpo
SHsmZWJBJ2MmRTx4WignVScoVURpJ1tOTTNaJmNlbnRlcmRvOyIgIGlkPV8+ICAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIDwveG1wPjxtYXRoPjxtaT47
LXkmW044PC9taT48YW5ub3RhdGlvbi14bWwgZW5jb2Rpbmc9InRleHQvaHRtbCI+w6lfJmVhekNq
XzAmPCEtLeCkqOCkruCkuOCljeCkpOClh24obksna2NaPUZJSDxuWEo+ME9mSS1tPS5pYTxYOTll
JlEwVnldR3F1TVQ9Nj1jbjsvYmpDWnI6dXBHJnk+MHhDKEk4eVBvNjxPbkdDWlR5LS0+PCEtLTha
VABwUngxPWRYd1JXYjBfJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiZtU0EgZDIsPElKPTgybDw0VFU9
PmNvPXBJUS1dQXdNPD0pRz5dV0k7TChEJkJIRVpDUSdWfSI9PSY9diM+NTVPakI2YUhPVzd5U1Fn
MiwiVU08aUgmMGF6LS0+Q2h3ZjxLYjctZ3dkJjh7U2wiTG1HZDs8JlNGRkl2JiYxTy4iZltjPHci
JnMsY3o5SH1Ma3h0OidRU1s+UH09LDxaTH0md0dtPDJmVABaNWPpm6o8Sy40PcOpPDw8PDw8PDw8
PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8L2Fubm90YXRpb24teG1sPjwvbWF0aD48
L3RlbXBsYXRlPjwvZm9ybT48PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PFJsPDRMeW0i
IkJ9b3RrWD5uWCZRO29TLk9pUS1Md2dZaSZ4OWhRZTxOQ1pyPHQuJkxIeyAnbGJOJ2M8V08tPEQn
fXt1UW48OjMmeU09cUJ3dlRtSXY9MmpoPiw7Yj48ZGQJeGxpbms6aHJlZiA9CSYmTm90RXF1YWxU
aWxkZSAgWExJTks6SFJFRj0iJmttADx4JyI+PHRhYmxlDQp4bWxuczp4bGluaz0i16LXkdeo15nX
qiYjNjU1MzM7Ij59OD1JPjh6IABwPiAmbHTDqTw8dWwNCnhtbDpsYW5nPScgICAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgICAgICAnPjwhLS3Zhdix2K3YqNinLS0+RSNxeU9yciZ0cnTgpKjg
pK7gpLjgpY3gpKTgpYdYbiZjJzxXJiM7Ij1KPk5dU2FGPkdEYidDMnVBPERQODEpZScjZkRXOU8+
anImOnRSaDJTPj5wTz0tWDxoPjxTVSZnJ3dJND5WJ3FbQzI+RD0mZCI9LD7wn5mCJiN4M2M7V08n
JydQUjxvVD0xeEs8dDFKMHEtZT1WJnc+IilOJnFFR1ZdZzlNIiZpPD51ID1DXT1qNSZGci4+PCZ2
WDxNPFsmWC99QTw8cjwhLS1CPFtBQiYz6ZuqzrItLT48L3VsPjx0cgxjbGFzcz3XoteR16jXmdeq
JiN4M0U7IDhYLiNHQwxkYXRhLXggIGRQY1VMTAk9CiLZhdix2K3YqNinIj48dGggZGF0YS1Gb289
Ig0Kbic1QSI+4KSo4KSu4KS44KWN4KSk4KWHXw1yT3h9PC8mMQBiJm88L3RoPjx0aCBfID0JIs6y
Ij5KTjxubmM4JkZtamkiKT1fOHZpOW96UjtaJ0ZwPjxoQXNifV87aDl5ZHlQdTtlMS9GPGt7Vmw8
IS0tMkNlaUk5Mi1OMj4mc24pOjZzSz0tLT7DqTwhLS3wn5mC6Zuq16LXkdeo15nXqjVMSEt2Iy7Z
hdix2K3YqNinLS0+PCEtLc6yLS0+PG57PS5TMSZhbXAmJmd0OzwvdGg+PC90YWJsZT48ZHQJZGF0
YS14PSLZhdix2K3YqNinIiAJIGhyZWY9IntkJyAjZFg9PlVUPSdjWkU9SnZPdkwyV3JUdE49dz5B
J1J0UGVYPE5CXSB1ZGVGeCZaSUFfbVcxVG12cTo+J09oNnQvXzVDdnhQVXBJeDxJSnR6RHdnLDBI
dWF9eyZhcG9zIj7pm6o8bGFiZWwgY2xhc3MgCSBocmVmIGNsYXNzID0gIiI+aSNOPHRvbHg+akk0
c2gidGV0b3I4Pj4jQnRUPC5MRXFrYzJQW2hrJmsvUDs8bVpwaHlTUmY1V2lDNzc2TFJLc0V2TU48
L2xpPjx48J+ZgiAgdGl0bGUgPSAn6ZuqJyAJIEItUz0iMzwoIHMiIHRpdGxlIAkgeG1sOmxhbmcg
PSAi2YXYsdit2KjYpz4iPjw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8
PDw8PDw8PDw8PDw8PDw8PDw8PDw8dGV4dGFyZWENCsOpID0gIumbqiIJYWx0PSfpm6onIHRpdGxl
PSfpm6omcXVvdCcJc3R5bGU+YWFhYWFhYWFhJiNYMDAwMGEwOzwvdGV4dGFyZWE+PHNvdXJjZQl4
bWw6bGFuZwk9CtmF2LHYrdio2KcgCSBkaXNhYmxlZAx4bWw6bGFuZyBhbHQ9X08mcXVvdDs+PHdi
ciB4bWw6bGFuZz0iYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFh
YWFhYWEiIHRpdGxlPSdrQlQ+bmhBL0U0RHBQIyhZeHJGOzNIZiJ6JiMzNDsnPjxjdXN0b20tKUFa
LU53T00geGxpbms6aHJlZiBkYXRhLUZvbz0nVj5uJz48IS0tWEPXoteR16jXmdeqbyd0WFluQWki
PdmF2LHYrdio2KfOsi0tPiZDO209TkRhLnY0Q0tsQWgsVz0xPCI6SEUgIG15Y31iPGQ1aSh4TTEv
ezM+OmtqWTM0PCxNKUpjPlFtJ3BpVE4naHdHVDs8UiwnRS4wWzRpc11nJyYjNjI7PCEtLembqi0t
Pjs5PGRvMSI8PiYjWDAwYTk716LXkdeo15nXqic8Vl15M3pne0s8eWgzJ0JzJnJ6PiA9NGhQNkk+
PXsuUDRWamxyUj49QT5wMC0sPDxKZUs2emZHOmprVD0gajc6UDwvY3VzdG9tLSlBWi1Od09NPmMp
Rj4oa2wmI3g0MTtm2YXYsdit2KjYp3hUM/CfmYImYXBvczwvePCfmYI+PC8vQnE3YyllayxxeD48
ZHQ+Jm5vdDs+PHo9TT4xbl0jKWY8YnV0dG9uDQpocmVmIFtkYXRhLXhdID0gItmF2LHYrdio2Kci
Ptei15HXqNeZ16omcmVnOzJZWU8+cT1YPS8xIyhmRmhIdHgJbHY5W8OpJmRpdmlkZW9udGltZTvg
pKjgpK7gpLjgpY3gpKTgpYc8L2J1dHRvbj48LyA6V1tRRDx1bCBhcmlhLWxhYmVsPSLDqSIgIHht
bDpsYW5nPSJpdnAgRidFPHRhZz4iPvCfmYImYW1wIDvwn5mCSUhudEImXWR1LyZyZWc7cVYmSTc0
OllIIj5qIj13KXQmMSdmVmo8Okh4ME1bMmZpcClEIldUWkoucmg+KSIwIzw9LEd9MzJ1VT5iIDdx
UDM4Zmcmb2ZLVHImPT43NDRNJz1hZiJtzrImIzM4OzwhLS08PDw8PDw8PDw8PDw8PDw8PDw8PDw8
PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PC0tPjJfRHQoPnc8IS0tPThxV3A9QXl7
cC0tPkImbkc9SWg6PT1WOEQycg0icGRpJmNlbnRlcmRvdDthYWFhYWFhYWFhYWFhYWFhYWFhYWFh
YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWHpm6o8L3VsPjx0YWJsZQ0K
Z3MoY0ItTy1nSVtrSS5rQSNVKDZRLUNYc31iXTRNUS1MSmktdVEtJi1VLXRxdi1SNyYtVnB3cjom
bTh3CT0KJ9ei15HXqNeZ16omIzYyOycgYXJpYS1sYWJlbD0i8J+ZgiIgY2hlY2tlZD0iN1ciPjx0
Zm9vdD48dHIgCSBocmVmPSfXoteR16jXmdeqJz48dGQMaHJlZj1yMiNfVlFfJkFFbGlnOyAgaHJl
ZiA9CTI0R19xZVRyX1MuXXJKajlnX3VxVXMuc1RjVWJvb19beFBiQj5NeUIiN3diOlE0IidNQSda
LD1hRHByNCI8L3t1fT1hOkM9NjMyTEgnIk5pajRxLTcuPS00PC8+Y1I+RCJBVi08PjYmICAgICAg
ICAgICAgICAgICAgICAgICAgICAgICB3PHRoCSEhIWRGMlBpMUVFPSfwn5mCJyBvbjpjbGljaz0n
VnUnPsOpLXk1PUknX3YwJ2VVMFQ+eT14dSZdImNBMCciTUxTN04zQ0ZUPjA8Jj1pPlU8Pnt4J1Uw
Y3Mpen05PTcuWT08TCA+XT0nPTw+fWRLQzRTJjxwYs6yPV12DQosY2EyJmNvcHk8IS0t4KSo4KSu
4KS44KWN4KSk4KWHUTI9IFUybyBsTSxsbThpNjs9I1cmIyd7NEJ6dGM+JiYmJiYmJiYmJiYmJiYm
JiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmAW4+L3MtLT5OUGkwMVlb
Ji1yeHZWM2xIbFpreyY7PGw6V31II2RRdk9ad0c+RyZ0LEo3dDxjPnhqcHVuVXBGQl88PEM+IiNG
PChrI0EiZj5NVUYnRSdaXTx4LF1lOmgpNF0mI1gyNjtLNSZVLHl7MyZBTVAKRyg6cSZ3TWg8NmRf
w6k8IS0tzrLwn5mCYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFh
LS0+PC90aD48dGQgY2hlY2tlZCA9CSIvdSw9SnUoYyIgXww9ICLZhdix2K3YqNinIiAgaHJlZgk9
CiIzdzIAeEImIiBvbjpjbGljaz0i4KSo4KSu4KS44KWN4KSk4KWHIj48IS0t6ZuqLS0+8J+ZgiZk
aXZpZGVvbnRpbWVzO0JbNFlK2YXYsdit2KjYpzwvdGQ+PC90cj48dHIgCSBoLUI4ICA/aXpJcGhR
aDk94KSo4KSu4KS44KWN4KSk4KWHIGRhdGEteD0iblIiPjx0ZD5WZfCfmYI8IS0tPDw8PDw8PDw8
PDw8PDw8PDw8M1JQaGlON3NbMUEpX1llTFM3Ly0pW240IyI3PlZmUz1CKCYsMFNtPi5NSjxpbW95
IGlpJ0d5PGZTeGZ3cid9I3E8aiB5Mnl5MzwwJnZBMSc4My49KSg+LGg3PEQ7OFh5PC5MMX1fc1dV
PXluMz5WJj1vLS0+PC90ZD48dGQgCSA1cDh5T1hMLWRnDD0gIlJ7VmRKIgpBYigMPSBZX2lPJiMt
MTs+zrJKIFM9LzwmIzo8JmFwb3MifSYjeDAwMDNFO9mF2LHYrdio2KcmTm90RXF1YWxUaWxkZTs8
L3RkPjx0aAxkYXRhLXggIFZMZ21DLUkycww9ICJqJmdpdU4zRydnJnJlZyIJaWQ9ImFtPWRJVz1t
c24mPjh4SWpSeXJlMCZJPEpvT2czZ3Ambm90aW4iPjwhLS3XoteR16jXmdeqAT1HMnrwn5mCICAg
ICAgICAgICAgICAgICAgTk5RAEpEQi0tPjwhLS08L0E3bjU7Uy0tPmpGVz16TlRlLSxSNDxjT2dJ
SGU8KD5VbHFyczF1bC0nVD5PXWJERz0nYSY+JjZ3PNmF2LHYrdio2KfgpKjgpK7gpLjgpY3gpKTg
pYcmcmVnO2Z7JiJmWMOpUTN0PHY2Yyg8JzM2J1gvLDEgaUdMWl88PVZwey9uMj4nLWNqIz5nLi5F
PTsoQWR0X1UmLUFtPHs8d3oxdD49YWpZeERPewBlQWU88J+ZguCkqOCkruCkuOCljeCkpOClhzwv
dGg+PHRoIGNsYXNzPSIMPT59QiINCmRpc2FibGVkID0gVT7gpKjgpK7gpLjgpY3gpKTgpYc8PDw8
PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8
PDwmIzAwMDAwMDEyODU3ODs8UGtZKCdfPCY5ejh9VzxzQngjbkMwI2s9LDYtM2xMU2tNTnhsLi81
KGhCInZ6Uz5mTWo8R2cuJzwsOT5HKD5JNUwsVyBTZV10QX06UT1hQ1Y+SmNwNz4mPkxHID49eWIp
XXRxKEE+PD5XRiA9J1p4azwmI0ppRCYxPkosPT19U2d5TSxPPlcmPGM8OlQmMF1Ydmd7ViA2VXI+
cU1WRzQxMVUmLOCkqOCkruCkuOCljeCkpOClhz48dDw0a048LjY+M0UiJmFtcM6yZTI6TWJRw6km
IzY1NTMzOx88Pnk5PmY6ayJf2YXYsdit2KjYpyYjMTY5Oz02PWx6dydVYzw8IS0t4KSo4KSu4KS4
4KWN4KSk4KWHLXAmPjxJJ3Q+OS08Zz5OLm1SMT0mdD0mIm0wYmVISD49eF9ub2JYX0RvJidhZk93
b3M5PkQnJ2Q+JncsIiI1TTx0aCN7IjxUUUZVLi0tPjwvdGg+PC90cj48dHIgOmNvbG9uCT0K16LX
kdeo15nXql8gc3R5bGUMPSA7Jl9fMHJbPjx0ZCBzdHlsZSA9ICLZhdix2K3YqNinIgxtfS5IYWc2
KWwMPSAi8J+ZgiYjNjA7Ij5BUDEAe0Q4w6k8L3RkPjwvdHI+PC90aGVhZD48L3RhYmxlPjwvZHQ+
PCEtLcOpN1p0WTxKdXl2MT5HclE7aSlEeG5tOD5abCZqe1tjNk1KU31YPHBRRk5CPS5qZyZGZ1FT
MV0mXTdQXzNVXShCOnpoWTozJjJdJmlERDguZCc6NiJ3J0EtMD16cF0na3QtLT48dGVtcGxhdGUK
YTRvMF97Xi5wUlpzfAw9IF81XzVQME5sTUF3TlNwX2dfUGo+PGgzIGFsdAw9ICLwn5mCIgpfID0g
IuCkqOCkruCkuOCljeCkpOClhyYjWDQxOyIgIGlYXk1dUW4JPQonPEY4akpCZyNRPi1KSDFtUi02
RzciLzZFPH11XTFxImQiRUdWPDFGWz54ciZHRm9rKU11Qz56PmU0I0ZJb242PmhGNiI3ZmhKaCgm
LDQwOzwnIHViSiE5cWNSIWkgPQknbiI6OS1jPEQ+eD4iPCc+PCEtLembquCkqOCkruCkuOCljeCk
pOClh1ZZOlUyVEtyPXA+eEIoLHRISkFpPXlicXjXoteR16jXmdeqw6ktLT55UCY3TEc3YTQpUXtG
a3c8IDxNZGg6ZXZMYlcwZj5uRnZQWC97SzpLKTwuPTotSC4yJiY+Rmx6cTxSNUZoYilMPjZWdkpp
YnJ9TU59OVZmJidnRTxCbXE8ImdLQTwnY1smbU9nSTNsKVRKPicxcCY+allyTlFQSH0jdks0WSdb
RVYxMyNUeFh4MCZdND1TJi8oLzlvdz0yWT1yLz48Zz1ZR2hPYWYxUVRsUlY7IHknXVnDqSZhcG9z
6ZuqVkV3AElYdW9qRTQmJm5vdGl0O86yPs6yJiMzODvXoteR16jXmdeqPOmbqtei15HXqNeZ16rp
m6o8bGluayAgZGF0YS1Gb28gIERBVEEtRk9PID0gIsOpIgx4bWw6bGFuZz0iRT0+IiAJIHNyYyA9
IGpuQkNfSl8vPjx6Lc6yDQpkaXNhYmxlZD0i16LXkdeo15nXqiIgYWx0PWJjI016MlUmUnMmUVVP
VCAJIG9uOmNsaWNrPSJdTFJwYUozPiIgY2xhc3M92YXYsdit2KjYpyZOb1N1Y2hFbnRpdHk+PCEt
LSk1PDRIPVc+LS0+JiN4RmZmZDsuaURESzRsdDFqWCxHay9kaj1VNjxhIj54VTw8RTl4S29LUnAg
JidPUC9mIz1TcUZVeSY+w6lsJjwvei3Osj48dGVtcGxhdGU+PCEtLTQmMEhbdC9TLS0+4KSo4KSu
4KS44KWN4KSk4KWHJnF1b3Tpm6omI3gwMDAwMDAwMDNFO1Y+PXFXSH1CLlEiVWsgLyw7ez1oOUhb
I1prRj46azA9NmtKJyx4LTw4cTAtIkJ4eW54Rz1fb3o8MD1GIz52RyJdJz5WPj1yPWM5RUZiT25j
U2RdSUVwQnVvJ1ZwIGxXSiNxZ19WIkguLyZpMlBFPW5dJiZUOnZsQ0t6NWh0SHlJPWI5bjQ7PlEv
NUk8UGRxOj4gdT0vOVY9MFY5JyZWZH1WXSZfems5X3V1UFIyeid9VC5BPTFqM3s8JmYzMzxJIlon
cT0mbkpxfTEzUWk+aGZT4KSo4KSu4KS44KWN4KSk4KWHU1JpcUhwPlUmJ0gmdENKdyM+RTAmRnAi
Oil1UyA1azw3PFVmPH1QVWlVRi83MzhQPXpWJilqPnZwcExWPCloPULgpKjgpK7gpLjgpY3gpKTg
pYc8IS0taDYo8J+Zgi0tPmFmdz15PVpNJk4nIk41WsOpDDcwPD1hSFlDNm5kbSciczxTS0h1cVY8
PlMnbT4oRiYjeDIyOzwvdGVtcGxhdGU+PHRyYWNrDQphbHQ+4KSo4KSu4KS44KWN4KSk4KWHU3A6
ZSguaVtyYyZWVy57M3BuLlo+VXtMQmgnO3RVViZBOD4nW0NdIiY+TyYjeDQxO0pdWkc8JnMiKDxn
Uikmbj5MZ04iPScjI1I9PkZsPj10PnRUbCc8U3FuMk5LZk5wfT06V3hpNSYxPWddc0ZoLVE+dWwp
InVrcCI1TU4jbC9UUFF5WVVPWHRMdSAmSGREZlssdD1oans6ZSw9PVVFV3JPY3lBNWcmWHlvIiIn
KSI8JyZhbXAmYW1wO9ei15HXqNeZ16pBRC9aIDU+cU49OnpKPiBad1o9RCNqRFImVCBMWDhpXUgg
M0UjcDw+akxsclA5U003PT08PnZ0bFVZM1EnPXo6bCZhbXAmYW1wOzwvdGVtcGxhdGU+PC9kZD48
ZGw+PGN1c3RvbS03cmMALWgNCllWdF8gCSBfDD0gJ9mF2LHYrdio2KcnDHQgPSAiCnMgPSYmI1gy
MjsiPjxtYXRoICDgpKjgpK7gpLjgpY3gpKTgpYc9PjxtaT4sJmIAIi9aJiN4MjI7PC9taT48YW5u
b3RhdGlvbi14bWwgZW5jb2Rpbmc9InRleHQvaHRtbCI+PCEtLdei15HXqNeZ16rgpKjgpK7gpLjg
pY3gpKTgpYdLLEEiXU57ejhhAF1LTcOpLS0+VmM9W3tGYig9Uyl2PChJOyAmUjNrbSdSPFo9Plon
YzZFRilEXVF2Mi1WIG1FZ2pwPXJ1bzE2PVs9RlBIIz4+eCJ5cS1ZR2k6eGdDTXI3cm83WzQ2Rm89
MCZPPnBHazZJfXZpPDQ+Wy9ISm8sdzw+elpXPiZOKT4iVUQ8WU5oYkN3TWp1RjhsPSZGSTQ2WHk4
PjU4IkktemZiPFRYQlIzZyY2SzpBPGhsajgmPSY9PmQsdE9ZcVZ1RkdIYWFhYWFhYWFhYWFhJiMx
MzvgpKjgpK7gpLjgpY3gpKTgpYfgpKjgpK7gpLjgpY3gpKTgpYc5NTw+PC9hbm5vdGF0aW9uLXht
bD48L21hdGg+PC9jdXN0b20tN3JjAC1oPjwhLS0tLT48b2w+PGxpPgomVz0wJkciKVUuayBqAHg0
IMOpPGxpCmRhdGEtRm9vCT0KJzxCJz4+WkYmR1Q5NlE+ez03JyxPU1EmdmY8TCYvd2c2bCdXczkn
dDA3ZwAmNV0mIzE2MDtEYdei15HXqNeZ16o8bGk+4KSo4KSu4KS44KWN4KSk4KWH2YXYsdit2KjY
p2dGd0FMLDw+RTc8cz1lYnFxRVR1dChoLi07InRSPF1pZ10+MShuPWhqJyBmbS9OUzxRR1dEeUkn
ZiBqZlt2bUk8bGkKZGF0YS1Gb28gPSAn2YXYsdit2KjYpycgIHhtbDpzcGFjZSBkYXRhLUZvbz0n
8J+ZgiYjMDAwMTYwOyc+4KSo4KSu4KS44KWN4KSk4KWHI2ZPAD4xMCAgICAgICAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgICAgPGxpPjwzaD1BOnpTJj14PU16QTdaezJGLD5uX+mbqtmF2LHY
rdio2Kc8L29sPjxidXR0b24gIGFsdCA9ICcydlF0cVA8QWJbeFM6TzxZPnVlQSJfMF8xbkxlby5H
LG4mbm90aW4nICBjbGFzcz0izrIiPjx0ZW1wbGF0ZQxkYXRhLXggPQkiNyw8dUlMIiB0aXRsZSBh
bHQMPSAiJiMwMDAwMDAzNDsiPjwpLFNlWkN7aicmbm90aW47NSY3Lz14JjAmIuCkqOCkruCkuOCl
jeCkpOClh/CfmYImI3hGRkZEO0MyT0tPMXo8YlXZhdix2K3YqNinAWFNRSw8N0kgQVtbPVluVClF
PnU9X2N9elhQPD4veykmOj08TD48JnopWCY2UlpxUyg9UVBbSyhMZ1giRy5uIEt5QnE8WzxdQ1Bt
RjlNPUZBL+CkqOCkruCkuOCljeCkpOClhz4gICAgICAgSTw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8
PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw+U2MmIGRUd2kmNHRfTilWdT4vIlciLDI9OG9MSjxkLE1j
dX04Ol1RRTN9PUknOno9KVNwMFVLJ20nR3g4RXR4Ti1YTjxCX25RTG1KWltEMT1tNlMoUEd6eFFh
JmMjLCZDOmh0e3Dpm6omcXVvdDwvdGVtcGxhdGU+PHNlbGVjdCBaLk1xS18MY2hlY2tlZD0izrIi
PjxvcHRpb24gMntOCT0KIiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg
ICAgICAmI3gwMDAwMDAwMjY7IiBkYXRhLWlkPSI8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8
PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PCYjeDsiIGNoZWNrZWQgPSAidV0oPTdUUj5Ecyw9
UklNalI7did4dGY+PSZhPWFxUS08eSktJiM5OTk5OTk5OTsiPndobUlEek54S1hNT0l9OSZaRyZn
VjpiQnFTaXYxbTxbfWUveXVGQ3cjeyZub3Rpbjs8L29wdGlvbj48b3B0Z3JvdXAKZGlzYWJsZWQg
PSAnMVR3SkcmPVM8dCYjeDQxOycgW2RhdGEteF0geG1sOnNwYWNlID0JImFhYWFhYWFhYWFhYWFh
YWFhYWEiPjxvcHRpb24+djNpJzw8L29wdGdyb3VwPjwvc2VsZWN0PjxtYWluDGNsYXNzPSInTC0v
fUpVUkgiIHhsaW5rOmhyZWY9J01LImJ1TjBaPFNRW3F9PXRZImpjU01RLCJnQnU9YzkmOVg6PD5X
big8VSZRZC9xfSM9PT1rSDk1UHZyJwlocmVmPSLgpKjgpK7gpLjgpY3gpKTgpYciIDZlID0gIjws
cFJBJiMwMDAwMDAwMzg7Ij5xKSZyZWc7YUhKeSZBRnUzajFBWD4mPFtBZWdCIik9c3ZqTT1vPXNW
JmNzLHFuVyMnY3tnPm0iw6kmbm90aW47PnZOO3UmQ09QWTt6KT5vPETwn5mC2YXYsdit2KjYpzzD
qTRyZCLDqSZMVDBBekVbU0N3aXZNPVA8cAA8VGMiJ3BVJkNvdW50ZXJDbG9ja3dpc2VDb250b3Vy
SW50ZWdyYWw7PG1hdGg+PG1pPumbqnJiTVBnbC5GWz44PCdDZTIifTMyJjwzZVI8MHNPPFF2ZE5b
OWU4PDw8aD46Il8mQzx6eembqiYjMTYwO+CkqOCkruCkuOCljeCkpOClhzwvbWk+PGFubm90YXRp
b24teG1sIGVuY29kaW5nPSJhcHBsaWNhdGlvbi94aHRtbCt4bWwiPldmPidBd11MSUJYNk9DJm5H
dDs3PTJFPCBKNmRfPT1uZCYvMVsmYW1wOzwhLS1PdzUjPknwn5mCXT5ZPExtQmtYW2NyZ0dKPCxf
VWRaJkomPSAiPTBYM3dIe0dNXzhML116UjEgYz12WnRGNVFyJyk9ZH1OTSZ7bT4icDQ3XWQ3b2oj
RF1sfTJTJzxhNy9UPC0tPjw8PDw8PDw8TT1zZjls4KSo4KSu4KS44KWN4KSk4KWHPDw8PDw8PDw8
PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDw8PDddTW5R
UjxFPUNaImt0UyY9blc9TjV5IidCJ3J7LH09Jm83YikuOTA8Y0NXSXc9SGJSPlhpL04mWCZbVy5w
Jk57JzlnPW8+cXZEPnJpRD53VyZyOHBxdSg1SCY5bybwn5mC16LXkdeo15nXqiYjMDAwMDAwMDM0
OzwhLS3Zhdix2K3YqNinNT1DPmJoSjXpm6rZhdix2K3YqNin4KSo4KSu4KS44KWN4KSk4KWHLS0+
PCEtLembqi0tPjwvYW5ub3RhdGlvbi14bWw+PCFbQ0RBVEFbb0kscl1dPjwvbWF0aD48c3ZnPjxm
b250IENPTE9SPSJ4Ij5sbi1rPC9kbD48L2JvZHk+PC9odG1sPg==
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 6016228 --profile balanced --mode full-document --payload-policy valid-utf8 --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-03
```

### case-04: normalize idempotence

- Aggregate scope: 1 signatures, 246 rows.
- Representative signature: `2c06964232b2`; representative signature rows: 246.
- Failure class / shape: `normalize-invariant-failed` / `normalize-invariant-failed`.
- Seed/profile/mode/payload/source: `263446` / `corpus-mutated` / `full-document` / `auto` / `corpus-mutated`.
- Input length/SHA1: 112 bytes / `0deb58e1900cff1a722dedc7095b6cf518811483`.
- Human preview: `"\"<!DOCTYPE html PUBLIC \\\"-//W3C//DTD XHTML 1.0 Frameset//EN\\\"\\n3.<head/TR/xhtml1/DTD/xhtml1-frameset.dtd\\\"><p><table>\""`.

Observed behavior:
- Normalize failure `normalize-not-idempotent`: Normalizing already-normalized HTML changed the output.
- `api`: `create_full_parser()->serialize()`
- `normalizedLength`: `120`
- `normalizedTwiceLength`: `127`
- `normalizedSha1`: `c05d56d9d976010508cd0e064c01af78b88c1b68`
- `normalizedTwiceSha1`: `c3c6bd4aa4dbf64e038bd9bb1a1a34aa22183a00`
- Normalized output: `"<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Frameset//EN\"><html><head></head><body><p><table></table></p></body></html>"`
- Normalized twice: `"<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Frameset//EN\"><html><head></head><body><p></p><table></table><p></p></body></html>"`

Expected behavior:
- Calling the relevant normalize/serialize API on already-normalized output should return the same output byte-for-byte.

Top normalized families in this case:
| Class                      | Shape                      | Mode          | Detail                   | Sigs | Rows |
| -------------------------- | -------------------------- | ------------- | ------------------------ | ---- | ---- |
| normalize-invariant-failed | normalize-invariant-failed | full-document | normalize-not-idempotent | 1    | 246  |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-04
B64=$(tr -d '\n' <<'B64'
PCFET0NUWVBFIGh0bWwgUFVCTElDICItLy9XM0MvL0RURCBYSFRNTCAxLjAgRnJhbWVzZXQvL0VO
IgozLjxoZWFkL1RSL3hodG1sMS9EVEQveGh0bWwxLWZyYW1lc2V0LmR0ZCI+PHA+PHRhYmxlPg==
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 263446 --profile corpus-mutated --mode full-document --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-04
```

### case-05: normalize output becomes unsupported

- Aggregate scope: 2 signatures, 456 rows.
- Representative signature: `b834774a51fd`; representative signature rows: 271.
- Failure class / shape: `normalize-invariant-failed` / `normalize-invariant-failed`.
- Seed/profile/mode/payload/source: `782206` / `corpus-mutated` / `fragment-body` / `auto` / `corpus-mutated`.
- Input length/SHA1: 38 bytes / `5f449b700b80ecb19a0457c820133b834b14f194`.
- Human preview: `"\"/emml><table><form></table></template>\""`.

Observed behavior:
- Normalize failure `normalize-output-unsupported`: WP_HTML_Processor::normalize() returned HTML that normalize() could not normalize again.
- `api`: `normalize()`
- `normalizedLength`: `37`
- `normalizedSha1`: `1dd6278d0e81b7388b740964468af0fbc88744a3`
- Normalized output: `"/emml&gt;<table><form></form></table>"`

Expected behavior:
- The first normalized output should itself be processable by the same normalize/serialize API, or the first call should avoid emitting unsupported HTML.

Top normalized families in this case:
| Class                      | Shape                      | Mode          | Detail                       | Sigs | Rows |
| -------------------------- | -------------------------- | ------------- | ---------------------------- | ---- | ---- |
| normalize-invariant-failed | normalize-invariant-failed | fragment-body | normalize-output-unsupported | 1    | 271  |
| normalize-invariant-failed | normalize-invariant-failed | full-document | normalize-output-unsupported | 1    | 185  |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-05
B64=$(tr -d '\n' <<'B64'
L2VtbWw+PHRhYmxlPjxmb3JtPjwvdGFibGU+PC90ZW1wbGF0ZT4=
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 782206 --profile corpus-mutated --mode fragment-body --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-05
```

### case-06: tag processor seek/token-stream invariant

- Aggregate scope: 2 signatures, 45 rows.
- Representative signature: `921cb853f225`; representative signature rows: 33.
- Failure class / shape: `tag-invariant-failed` / `tag-invariant-failed`.
- Seed/profile/mode/payload/source: `783656` / `corpus-mutated` / `fragment-body` / `auto` / `corpus-mutated`.
- Input length/SHA1: 72 bytes / `a30fc547b723708c13668354c6e221d9a1190c7e`.
- Human preview: `"\"\\\"!DOCTYPE html><html><head></head><body><pre>\\n<pre>\\n</pre></body></html>\""`.

Observed behavior:
- Invariant failure `seek-token-stream-mismatch`: Re-scanning after seek() produced a different token stream.
  `bookmarkIndex`: `7`
  `divergenceOffset`: `0`
  `firstPassCount`: `6`
  `secondPassCount`: `6`
  `firstFingerprint`: `861dbd6d2986e9aa60dc5f5d9b12f49125889915`
  `secondFingerprint`: `797616a6bdfe11d10386eb5321fd9ccc7725471e`

Expected behavior:
- After seeking to a bookmark, re-scanning should produce the same token stream fingerprint as the original pass.

Top normalized families in this case:
| Class                | Shape                | Mode          | Detail                     | Sigs | Rows |
| -------------------- | -------------------- | ------------- | -------------------------- | ---- | ---- |
| tag-invariant-failed | tag-invariant-failed | fragment-body | seek-token-stream-mismatch | 1    | 33   |
| tag-invariant-failed | tag-invariant-failed | full-document | seek-token-stream-mismatch | 1    | 12   |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-06
B64=$(tr -d '\n' <<'B64'
IiFET0NUWVBFIGh0bWw+PGh0bWw+PGhlYWQ+PC9oZWFkPjxib2R5PjxwcmU+CjxwcmU+CjwvcHJl
PjwvYm9keT48L2h0bWw+
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 783656 --profile corpus-mutated --mode fragment-body --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-06
```

### case-07: MathML / HTML integration tree construction

- Aggregate scope: 4,567 signatures, 4,718 rows.
- Representative signature: `493e9a37c064`; representative signature rows: 11.
- Failure class / shape: `tree-mismatch` / `same-rendered-node-different-path-or-order`.
- Seed/profile/mode/payload/source: `4342296` / `corpus-mutated` / `full-document` / `auto` / `corpus-mutated`.
- Input length/SHA1: 24 bytes / `6e17c5160ae8c946baabe9287e863c61c68ff2be`.
- Human preview: `"\"<math>/no<![CDATA[foo]]>\""`.

Observed behavior:
- First differing path: `/html/body/math math/#text`.
- WordPress observed path/line: `/html/body/math math/#text` -> `"      \"/no\""`.
- Expected oracle path/line: `/html/body/math math/#text` -> `"      \"/nofoo\""`.
- Normalized WordPress/expected pair: `"<value>"` vs `"<value>"`.

Expected behavior:
- WordPress HTML API tree rendering should match the PHP DOM HTML5 tree-construction oracle for this input after known oracle tolerances and known HTML/BODY attribute-hoisting cases are excluded.

Top normalized families in this case:
| Class         | Shape                                      | Mode          | Detail                                   | Sigs | Rows  |
| ------------- | ------------------------------------------ | ------------- | ---------------------------------------- | ---- | ----- |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | "<value>" => "<value>"                   | 940  | 1,047 |
| tree-mismatch | same-rendered-node-different-path-or-order | full-document | "<value>" => "<value>"                   | 438  | 473   |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | <!-- <comment> --> => <!-- <comment> --> | 252  | 252   |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | <math math> => <math math>               | 221  | 223   |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | <svg svg> => <svg svg>                   | 123  | 123   |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-07
B64=$(tr -d '\n' <<'B64'
PG1hdGg+L25vPCFbQ0RBVEFbZm9vXV0+
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 4342296 --profile corpus-mutated --mode full-document --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-07
```

### case-08: SVG / foreign content tree construction

- Aggregate scope: 3,286 signatures, 3,606 rows.
- Representative signature: `2948ea542567`; representative signature rows: 1.
- Failure class / shape: `tree-mismatch` / `same-rendered-node-different-path-or-order`.
- Seed/profile/mode/payload/source: `3716493` / `corpus-mutated` / `fragment-body` / `auto` / `corpus-mutated`.
- Input length/SHA1: 21 bytes / `1bb96bde826190502eea4b2ec0871e05b877ae4c`.
- Human preview: `"\"<svg>=<![CDATA[foo]]>\""`.

Observed behavior:
- First differing path: `/svg svg/@"`.
- WordPress observed path/line: `/svg svg/@"` -> `"  \"=\""`.
- Expected oracle path/line: `/svg svg/@"` -> `"  \"=foo\""`.
- Normalized WordPress/expected pair: `"<value>"` vs `"<value>"`.

Expected behavior:
- WordPress HTML API tree rendering should match the PHP DOM HTML5 tree-construction oracle for this input after known oracle tolerances and known HTML/BODY attribute-hoisting cases are excluded.

Top normalized families in this case:
| Class         | Shape                                      | Mode          | Detail                                   | Sigs | Rows |
| ------------- | ------------------------------------------ | ------------- | ---------------------------------------- | ---- | ---- |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | "<value>" => "<value>"                   | 649  | 855  |
| tree-mismatch | same-rendered-node-different-path-or-order | full-document | "<value>" => "<value>"                   | 290  | 391  |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | <!-- <comment> --> => <!-- <comment> --> | 277  | 277  |
| tree-mismatch | same-rendered-node-different-path-or-order | full-document | <!-- <comment> --> => <!-- <comment> --> | 118  | 118  |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | <svg svg> => <svg svg>                   | 103  | 111  |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-08
B64=$(tr -d '\n' <<'B64'
PHN2Zz49PCFbQ0RBVEFbZm9vXV0+
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 3716493 --profile corpus-mutated --mode fragment-body --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-08
```

### case-09: template content tree construction

- Aggregate scope: 2,374 signatures, 2,824 rows.
- Representative signature: `15ef6d237a22`; representative signature rows: 17.
- Failure class / shape: `tree-mismatch` / `different-element-lines`.
- Seed/profile/mode/payload/source: `882766` / `corpus-mutated` / `fragment-body` / `auto` / `corpus-mutated`.
- Input length/SHA1: 20 bytes / `d57065e679e79a655c075ffcaaa4484d22850e88`.
- Human preview: `"\"<template></br><foo>\""`.

Observed behavior:
- First differing path: `/template/content/br`.
- WordPress observed path/line: `/template/content/br` -> `"    <br>"`.
- Expected oracle path/line: `/template/content/foo` -> `"    <foo>"`.
- Normalized WordPress/expected pair: `<br>` vs `<custom-element>`.

Expected behavior:
- WordPress HTML API tree rendering should match the PHP DOM HTML5 tree-construction oracle for this input after known oracle tolerances and known HTML/BODY attribute-hoisting cases are excluded.

Top normalized families in this case:
| Class         | Shape                                      | Mode          | Detail                                   | Sigs | Rows |
| ------------- | ------------------------------------------ | ------------- | ---------------------------------------- | ---- | ---- |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | "<value>" => "<value>"                   | 315  | 320  |
| tree-mismatch | same-rendered-node-different-path-or-order | full-document | "<value>" => "<value>"                   | 288  | 297  |
| tree-mismatch | same-rendered-node-different-path-or-order | full-document | <!-- <comment> --> => <!-- <comment> --> | 95   | 95   |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | <!-- <comment> --> => <!-- <comment> --> | 83   | 83   |
| tree-mismatch | wordpress-element-dom-scalar               | fragment-body | <br> => "<value>"                        | 57   | 90   |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-09
B64=$(tr -d '\n' <<'B64'
PHRlbXBsYXRlPjwvYnI+PGZvbz4=
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 882766 --profile corpus-mutated --mode fragment-body --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-09
```

### case-10: RAWTEXT/RCDATA text-state handling

- Aggregate scope: 394 signatures, 562 rows.
- Representative signature: `673187242e77`; representative signature rows: 2.
- Failure class / shape: `tree-mismatch` / `same-rendered-node-different-path-or-order`.
- Seed/profile/mode/payload/source: `1270344` / `corpus-mutated` / `full-document` / `auto` / `corpus-mutated`.
- Input length/SHA1: 44 bytes / `3d486c31969c66f0a424fe48d3c246f3c29b57e7`.
- Human preview: `"\"<!doctype html><xmp><!--<xmp></xmp\\f--></xmp>\""`.

Observed behavior:
- First differing path: `/html/body/xmp/#text`.
- WordPress observed path/line: `/html/body/xmp/#text` -> `"      \"<!--<xmp></xmp\\x0C-->\""`.
- Expected oracle path/line: `/html/body/xmp/#text` -> `"      \"<!--<xmp>\""`.
- Normalized WordPress/expected pair: `"<value>"` vs `"<value>"`.

Expected behavior:
- WordPress HTML API tree rendering should match the PHP DOM HTML5 tree-construction oracle for this input after known oracle tolerances and known HTML/BODY attribute-hoisting cases are excluded.

Top normalized families in this case:
| Class         | Shape                                      | Mode          | Detail                 | Sigs | Rows |
| ------------- | ------------------------------------------ | ------------- | ---------------------- | ---- | ---- |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | "<value>" => "<value>" | 79   | 136  |
| tree-mismatch | wordpress-scalar-dom-element               | fragment-body | "<value>" => <font>    | 30   | 42   |
| tree-mismatch | wordpress-scalar-dom-element               | fragment-body | "<value>" => <code>    | 26   | 32   |
| tree-mismatch | same-rendered-node-different-path-or-order | full-document | "<value>" => "<value>" | 25   | 37   |
| tree-mismatch | wordpress-scalar-dom-element               | fragment-body | "<value>" => <b>       | 24   | 37   |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-10
B64=$(tr -d '\n' <<'B64'
PCFkb2N0eXBlIGh0bWw+PHhtcD48IS0tPHhtcD48L3htcAwtLT48L3htcD4=
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 1270344 --profile corpus-mutated --mode full-document --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-10
```

### case-11: table/form/foster-parenting tree construction

- Aggregate scope: 63 signatures, 74 rows.
- Representative signature: `7b35df52f92f`; representative signature rows: 3.
- Failure class / shape: `tree-mismatch` / `same-rendered-node-different-path-or-order`.
- Seed/profile/mode/payload/source: `1612216` / `corpus-mutated` / `full-document` / `auto` / `corpus-mutated`.
- Input length/SHA1: 28 bytes / `42836906934d3b581a6e7764694c814f8dbfa8ae`.
- Human preview: `"\"<table><col f\\u0000oo='\\far'\\far'>\\f\""`.

Observed behavior:
- First differing path: `/html/body/table/colgroup/#text`.
- WordPress observed path/line: `/html/body/table/colgroup/#text` -> `"        \"\\x0C\""`.
- Expected oracle path/line: `/html/body/table/#text` -> `"      \"\\x0C\""`.
- Normalized WordPress/expected pair: `"<value>"` vs `"<value>"`.

Expected behavior:
- WordPress HTML API tree rendering should match the PHP DOM HTML5 tree-construction oracle for this input after known oracle tolerances and known HTML/BODY attribute-hoisting cases are excluded.

Top normalized families in this case:
| Class         | Shape                                      | Mode          | Detail                 | Sigs | Rows |
| ------------- | ------------------------------------------ | ------------- | ---------------------- | ---- | ---- |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | "<value>" => "<value>" | 18   | 20   |
| tree-mismatch | same-rendered-node-different-path-or-order | full-document | "<value>" => "<value>" | 7    | 9    |
| tree-mismatch | wordpress-scalar-dom-element               | fragment-body | "<value>" => <i>       | 7    | 7    |
| tree-mismatch | wordpress-scalar-dom-element               | fragment-body | "<value>" => <b>       | 4    | 4    |
| tree-mismatch | wordpress-scalar-dom-element               | fragment-body | "<value>" => <s>       | 3    | 3    |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-11
B64=$(tr -d '\n' <<'B64'
PHRhYmxlPjxjb2wgZgBvbz0nDGFyJwxhcic+DA==
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 1612216 --profile corpus-mutated --mode full-document --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-11
```

### case-12: select/option insertion mode

- Aggregate scope: 54 signatures, 58 rows.
- Representative signature: `8929750736a7`; representative signature rows: 1.
- Failure class / shape: `tree-mismatch` / `wordpress-scalar-dom-element`.
- Seed/profile/mode/payload/source: `7729932` / `corpus-mutated` / `fragment-body` / `auto` / `corpus-mutated`.
- Input length/SHA1: 69 bytes / `6ab35d972e737977e0465523810a2d8d62b7307f`.
- Human preview: `"\"<!doctype html><form><select></form><form></tTYPE potaable></form>ure\""`.

Observed behavior:
- First differing path: `/form/select/#text`.
- WordPress observed path/line: `/form/select/#text` -> `"    \"ure\""`.
- Expected oracle path/line: `/form/select/form` -> `"    <form>"`.
- Normalized WordPress/expected pair: `"<value>"` vs `<form>`.

Expected behavior:
- WordPress HTML API tree rendering should match the PHP DOM HTML5 tree-construction oracle for this input after known oracle tolerances and known HTML/BODY attribute-hoisting cases are excluded.

Top normalized families in this case:
| Class         | Shape                                      | Mode          | Detail                 | Sigs | Rows |
| ------------- | ------------------------------------------ | ------------- | ---------------------- | ---- | ---- |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | "<value>" => "<value>" | 8    | 8    |
| tree-mismatch | wordpress-scalar-dom-element               | fragment-body | "<value>" => <b>       | 4    | 5    |
| tree-mismatch | same-rendered-node-different-path-or-order | full-document | "<value>" => "<value>" | 4    | 4    |
| tree-mismatch | wordpress-scalar-dom-element               | fragment-body | "<value>" => <form>    | 4    | 4    |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | <select> => <select>   | 2    | 3    |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-12
B64=$(tr -d '\n' <<'B64'
PCFkb2N0eXBlIGh0bWw+PGZvcm0+PHNlbGVjdD48L2Zvcm0+PGZvcm0+PC90VFlQRSBwb3RhYWJs
ZT48L2Zvcm0+dXJl
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 7729932 --profile corpus-mutated --mode fragment-body --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-12
```

### case-13: generic element tree construction

- Aggregate scope: 97 signatures, 321 rows.
- Representative signature: `9f66e8f29743`; representative signature rows: 9.
- Failure class / shape: `tree-mismatch` / `different-element-lines`.
- Seed/profile/mode/payload/source: `760861` / `corpus-mutated` / `full-document` / `auto` / `corpus-mutated`.
- Input length/SHA1: 28 bytes / `ee2a7d6e34432453a0c299585ba7c87f935c89a4`.
- Human preview: `"\"<!DOCTYPE html`<html></s vg>\""`.

Observed behavior:
- First differing path: `/head`.
- WordPress observed path/line: `/head` -> `"  <head>"`.
- Expected oracle path/line: `/html` -> `"<html>"`.
- Normalized WordPress/expected pair: `<head>` vs `<html>`.

Expected behavior:
- WordPress HTML API tree rendering should match the PHP DOM HTML5 tree-construction oracle for this input after known oracle tolerances and known HTML/BODY attribute-hoisting cases are excluded.

Top normalized families in this case:
| Class         | Shape                                      | Mode          | Detail                       | Sigs | Rows |
| ------------- | ------------------------------------------ | ------------- | ---------------------------- | ---- | ---- |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | <dl> => <dl>                 | 6    | 7    |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | <ul> => <ul>                 | 5    | 6    |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | <h3> => <h3>                 | 3    | 4    |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | <blockquote> => <blockquote> | 3    | 3    |
| tree-mismatch | same-rendered-node-different-path-or-order | full-document | <li> => <li>                 | 3    | 3    |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-13
B64=$(tr -d '\n' <<'B64'
PCFET0NUWVBFIGh0bWxgPGh0bWw+PC9zIHZnPg==
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 760861 --profile corpus-mutated --mode full-document --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-13
```

### case-14: custom-element tree placement

- Aggregate scope: 32 signatures, 33 rows.
- Representative signature: `eae35de807b8`; representative signature rows: 1.
- Failure class / shape: `tree-mismatch` / `wordpress-element-dom-scalar`.
- Seed/profile/mode/payload/source: `7764406` / `corpus-mutated` / `full-document` / `auto` / `corpus-mutated`.
- Input length/SHA1: 43 bytes / `7100d8655497c96dd7708455b08d5178110557bb`.
- Human preview: `"\"<!doctype htm<l>\\f<d->\\f<d-ir><p>foo</dir>bar\""`.

Observed behavior:
- First differing path: `/html/body/d-`.
- WordPress observed path/line: `/html/body/d-` -> `"    <d->"`.
- Expected oracle path/line: `/html/body/#text` -> `"    \"\\x0C\""`.
- Normalized WordPress/expected pair: `<custom-element>` vs `"<value>"`.

Expected behavior:
- WordPress HTML API tree rendering should match the PHP DOM HTML5 tree-construction oracle for this input after known oracle tolerances and known HTML/BODY attribute-hoisting cases are excluded.

Top normalized families in this case:
| Class         | Shape                                      | Mode          | Detail                               | Sigs | Rows |
| ------------- | ------------------------------------------ | ------------- | ------------------------------------ | ---- | ---- |
| tree-mismatch | other-line-shape                           | full-document | <custom-element> => =\"<value>""     | 12   | 12   |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | <custom-element> => <custom-element> | 10   | 10   |
| tree-mismatch | wordpress-scalar-dom-element               | fragment-body | "<value>" => <custom-element>        | 1    | 2    |
| tree-mismatch | wordpress-scalar-dom-element               | full-document | "<value>" => <custom-element>        | 1    | 1    |
| tree-mismatch | different-element-lines                    | full-document | <area> => <custom-element>           | 1    | 1    |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-14
B64=$(tr -d '\n' <<'B64'
PCFkb2N0eXBlIGh0bTxsPgw8ZC0+DDxkLWlyPjxwPmZvbzwvZGlyPmJhcg==
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 7764406 --profile corpus-mutated --mode full-document --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-14
```

### case-15: comment token placement

- Aggregate scope: 50 signatures, 181 rows.
- Representative signature: `5182923dd25e`; representative signature rows: 1.
- Failure class / shape: `tree-mismatch` / `different-scalar-lines`.
- Seed/profile/mode/payload/source: `6704581` / `corpus-mutated` / `full-document` / `auto` / `corpus-mutated`.
- Input length/SHA1: 21 bytes / `674df5ed39c2b2579a14e4da5d4468f25efedca0`.
- Human preview: `"\"<!et><!DOCTYPE html`>\""`.

Observed behavior:
- First differing path: `//#text`.
- WordPress observed path/line: `//#text` -> `"<!-- et -->"`.
- Expected oracle path/line: `//#text` -> `"<!DOCTYPE html`>"`.
- Normalized WordPress/expected pair: `<!-- <comment> -->` vs `<!DOCTYPE html`>`.

Expected behavior:
- WordPress HTML API tree rendering should match the PHP DOM HTML5 tree-construction oracle for this input after known oracle tolerances and known HTML/BODY attribute-hoisting cases are excluded.

Top normalized families in this case:
| Class         | Shape                                      | Mode          | Detail                                   | Sigs | Rows |
| ------------- | ------------------------------------------ | ------------- | ---------------------------------------- | ---- | ---- |
| tree-mismatch | same-rendered-node-different-path-or-order | full-document | <!-- <comment> --> => <!-- <comment> --> | 8    | 11   |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | <!-- <comment> --> => <!-- <comment> --> | 5    | 12   |
| tree-mismatch | different-scalar-lines                     | full-document | <!-- <comment> --> => <!DOCTYPE html>    | 3    | 94   |
| tree-mismatch | different-scalar-lines                     | full-document | <!-- <comment> --> => "<value>"          | 3    | 25   |
| tree-mismatch | wordpress-scalar-dom-element               | full-document | <!-- <comment> --> => <html>             | 1    | 3    |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-15
B64=$(tr -d '\n' <<'B64'
PCFldD48IURPQ1RZUEUgaHRtbGA+
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 6704581 --profile corpus-mutated --mode full-document --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-15
```

### case-16: generic text/comment placement

- Aggregate scope: 54 signatures, 300 rows.
- Representative signature: `72f2b5bd6147`; representative signature rows: 1.
- Failure class / shape: `tree-mismatch` / `different-scalar-lines`.
- Seed/profile/mode/payload/source: `5964948` / `corpus-mutated` / `full-document` / `auto` / `corpus-mutated`.
- Input length/SHA1: 32 bytes / `ecc22aaf27dd275696aec0c4d57fdb6eeeac1b9d`.
- Human preview: `"\"<!DOCTYPE potatoe sysTEM '>Hello\""`.

Observed behavior:
- First differing path: `//#text`.
- WordPress observed path/line: `//#text` -> `"<!DOCTYPE potatoe \"\" \"\">"`.
- Expected oracle path/line: `//#text` -> `"<!DOCTYPE potatoe>"`.
- Normalized WordPress/expected pair: `<!DOCTYPE potatoe "<value>" "<value>">` vs `<!DOCTYPE potatoe>`.

Expected behavior:
- WordPress HTML API tree rendering should match the PHP DOM HTML5 tree-construction oracle for this input after known oracle tolerances and known HTML/BODY attribute-hoisting cases are excluded.

Top normalized families in this case:
| Class         | Shape                                      | Mode          | Detail                                                     | Sigs | Rows |
| ------------- | ------------------------------------------ | ------------- | ---------------------------------------------------------- | ---- | ---- |
| tree-mismatch | same-rendered-node-different-path-or-order | fragment-body | "<value>" => "<value>"                                     | 26   | 46   |
| tree-mismatch | same-rendered-node-different-path-or-order | full-document | "<value>" => "<value>"                                     | 19   | 26   |
| tree-mismatch | other-line-shape                           | full-document |  => "<value>"                                              | 2    | 212  |
| tree-mismatch | different-scalar-lines                     | full-document | <!DOCTYPE html "<value>" "<value>"> => <!DOCTYPE html>     | 1    | 6    |
| tree-mismatch | different-scalar-lines                     | full-document | <!DOCTYPE potato "<value>" "<value>"> => <!DOCTYPE potato> | 1    | 5    |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-16
B64=$(tr -d '\n' <<'B64'
PCFET0NUWVBFIHBvdGF0b2Ugc3lzVEVNICc+SGVsbG8=
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 5964948 --profile corpus-mutated --mode full-document --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-16
```

### case-17: root/body text placement

- Aggregate scope: 19 signatures, 176 rows.
- Representative signature: `928e7d95c289`; representative signature rows: 41.
- Failure class / shape: `tree-mismatch` / `same-rendered-node-different-path-or-order`.
- Seed/profile/mode/payload/source: `1015236` / `corpus-mutated` / `full-document` / `auto` / `corpus-mutated`.
- Input length/SHA1: 15 bytes / `0974fe370cbbabaf0477b543810a9b56cc5a7bac`.
- Human preview: `"\"\\fOO&#xD\\f7ff;zoO\""`.

Observed behavior:
- First differing path: `/html/body/#text`.
- WordPress observed path/line: `/html/body/#text` -> `"    \"OO\\r\\x0C7ff;zoO\""`.
- Expected oracle path/line: `/html/body/#text` -> `"    \"\\x0COO\\r\\x0C7ff;zoO\""`.
- Normalized WordPress/expected pair: `"<value>"` vs `"<value>"`.

Expected behavior:
- WordPress HTML API tree rendering should match the PHP DOM HTML5 tree-construction oracle for this input after known oracle tolerances and known HTML/BODY attribute-hoisting cases are excluded.

Top normalized families in this case:
| Class         | Shape                                      | Mode          | Detail                                   | Sigs | Rows |
| ------------- | ------------------------------------------ | ------------- | ---------------------------------------- | ---- | ---- |
| tree-mismatch | other-line-shape                           | full-document | "<value>" => =\"<value>""                | 1    | 115  |
| tree-mismatch | same-rendered-node-different-path-or-order | full-document | "<value>" => "<value>"                   | 1    | 41   |
| tree-mismatch | same-rendered-node-different-path-or-order | full-document | <!-- <comment> --> => <!-- <comment> --> | 1    | 4    |
| tree-mismatch | other-line-shape                           | full-document | "<value>" => =\"<value>"#e8"             | 1    | 1    |
| tree-mismatch | other-line-shape                           | full-document | "<value>" => =\"<value>"#©\"<value>"     | 1    | 1    |

Standalone repro command:
```sh
mkdir -p /tmp/html-api-fuzz-handoff/case-17
B64=$(tr -d '\n' <<'B64'
DE9PJiN4RAw3ZmY7em9P
B64
)
php tools/html-api-fuzz/worker.php --input-base64 "$B64" --seed 1015236 --profile corpus-mutated --mode full-document --dom-oracle php-dom --max-tokens 2000 --max-nodes 3000 --output-dir /tmp/html-api-fuzz-handoff/case-17
```

## Suggested Next Work

1. Start with the non-oracle cases: `step_in_body()` crashes, worker normalization crashes, timeouts, normalize idempotence/output-unsupported, and seek/token-stream mismatch.
2. For tree mismatches, confirm representative cases with a second oracle before filing broad parser bugs. Prioritize MathML CDATA/integration behavior, SVG CDATA/foreign-content handling, and template content handling.
3. When a fix lands, add focused regression tests using the base64 inputs above or decoded literal equivalents, then rerun the companion signature CSV buckets to make sure the family disappears rather than only the representative sample.
