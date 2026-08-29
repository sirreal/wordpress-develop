#!/usr/bin/env python3
"""Deterministic JSON -> Markdown documentation renderer.

Converts phpdoc-parser JSON (as produced for the WordPress HTML API classes)
into a single Markdown file optimized for an LLM agent reading the docs to
write code against the API.

Usage:
    python3 render-docs-markdown.py -i input.json -o output.md

Design constraints:
  * Standard library only.
  * Deterministic: identical input bytes -> identical output bytes. JSON order
    is preserved; nothing depends on dict-iteration order, timestamps, or
    randomness.
  * Unknown/unhandled HTML tags cause a loud failure (sys.exit) so that schema
    drift in future inputs is noticed rather than silently dropped.
"""

import argparse
import html
import json
import re
import sys
from html.parser import HTMLParser


def die(message):
    """Abort loudly. Used for unhandled HTML tags / schema drift."""
    sys.exit("render-docs-markdown.py: ERROR: " + message)


# ---------------------------------------------------------------------------
# HTML -> Markdown conversion
# ---------------------------------------------------------------------------
#
# phpDocumentor renders docblock Markdown to HTML. We invert that back to clean
# Markdown. The full tag inventory observed across both artifact files is:
#
#   block:  p, pre, ul, ol, li, h2, h3, h4, blockquote,
#           table, thead, tbody, tr, th, td
#   inline: br, code, em, strong, a
#
# `div` appears ONLY as literal example text in short descriptions and inside
# hash-notation @param blocks (e.g. "stop on tag closers, e.g. </div>"). It is
# not structural markup, so we re-emit it verbatim as literal text rather than
# treating it as a layout element. It is the one tolerated "non-structural" tag;
# anything outside the known sets aborts.

# Inline tags that produce Markdown inline spans.
_INLINE_TAGS = {"br", "code", "em", "strong", "a"}

# Block tags that participate in layout.
_BLOCK_TAGS = {
    "p", "pre", "ul", "ol", "li", "h2", "h3", "h4", "blockquote",
    "table", "thead", "tbody", "tr", "th", "td",
}

# Tags whose original source is re-emitted as literal text (documented quirk:
# unescaped example HTML inside prose). Kept deliberately narrow.
_LITERAL_TAGS = {"div"}

_ALL_KNOWN_TAGS = _INLINE_TAGS | _BLOCK_TAGS | _LITERAL_TAGS


def _reconstruct_start(tag, attrs):
    """Re-emit a literal-passthrough start tag verbatim as plain text."""
    if not attrs:
        return "<%s>" % tag
    rendered = "<" + tag
    for k, v in attrs:
        if v is None:
            rendered += " " + k
        else:
            rendered += ' %s="%s"' % (k, v)
    return rendered + ">"

# Heading levels. The JSON only contains h2-h4; we keep h2->## but shift down by
# one inside method/property bodies so docblock headings never collide with the
# document's own structural headings. Shifting is applied by the caller via the
# `heading_shift` argument.
_HEADING_BASE = {"h2": 2, "h3": 3, "h4": 4}


class _MarkdownBuilder:
    """Accumulates Markdown output from a stream of parser events.

    The builder is a small block model: a list of "blocks" (paragraphs, code
    fences, list items, headings, table rows, blockquote lines). Inline content
    is buffered into the current block until a block boundary flushes it.
    """

    def __init__(self, heading_shift):
        self._heading_shift = heading_shift
        self._blocks = []          # list of rendered block strings
        self._inline = []          # current inline buffer (list of str)
        self._list_stack = []      # stack of ("ul"|"ol", item_counter)
        self._in_pre = False
        self._pre_buf = []
        self._in_blockquote = False
        self._table_rows = []      # list of (is_header, [cell_md, ...])
        self._table_row_cells = None
        self._table_cell_buf = None
        self._in_table = False

    # -- inline buffer helpers ------------------------------------------
    def _emit_text(self, text):
        if self._in_pre:
            self._pre_buf.append(text)
        elif self._table_cell_buf is not None:
            self._table_cell_buf.append(text)
        else:
            self._inline.append(text)

    def _emit_inline(self, markup):
        """Emit already-formatted inline markup (not subject to escaping)."""
        if self._in_pre:
            # Inside <pre> nothing is treated as inline markup.
            self._pre_buf.append(markup)
        elif self._table_cell_buf is not None:
            self._table_cell_buf.append(markup)
        else:
            self._inline.append(markup)

    def _take_inline(self):
        text = "".join(self._inline)
        self._inline = []
        # Collapse runs of whitespace (HTML whitespace semantics) but keep
        # explicit line breaks that were emitted as "\n".
        # We intentionally collapse spaces/newlines introduced by source
        # indentation in the original HTML.
        text = re.sub(r"[ \t]*\n[ \t]*", "\n", text)
        text = re.sub(r"[ \t]{2,}", " ", text)
        return text.strip()

    # -- block helpers --------------------------------------------------
    def _add_block(self, block):
        if block:
            self._blocks.append(block)

    def _flush_paragraph(self):
        text = self._take_inline()
        if not text:
            return
        if self._in_blockquote:
            self._add_block("\n".join("> " + ln for ln in text.split("\n")))
        else:
            self._add_block(text)

    # -- start tags -----------------------------------------------------
    def start(self, tag, attrs):
        if tag in _LITERAL_TAGS:
            self._emit_text(_reconstruct_start(tag, attrs))
            return
        # Inside a <pre> block, inline markup is meaningless: the content is
        # verbatim. Suppress inline tags so their Markdown markers (backticks,
        # asterisks) do not leak into fenced code. Only raw text is collected.
        if self._in_pre and tag in _INLINE_TAGS:
            return
        if tag == "br":
            if self._table_cell_buf is not None:
                self._table_cell_buf.append("<br>")
            else:
                self._inline.append("\n")
            return
        if tag == "p":
            self._flush_paragraph()
            return
        if tag in ("em",):
            self._emit_inline("*")
            return
        if tag in ("strong",):
            self._emit_inline("**")
            return
        if tag == "code":
            self._emit_inline("`")
            return
        if tag == "a":
            # Links: open marker; href captured for the close.
            href = ""
            for k, v in attrs:
                if k == "href":
                    href = v or ""
            self._a_href_stack = getattr(self, "_a_href_stack", [])
            self._a_href_stack.append(href)
            self._emit_inline("[")
            return
        if tag == "pre":
            self._flush_paragraph()
            self._in_pre = True
            self._pre_buf = []
            return
        if tag in ("ul", "ol"):
            self._flush_paragraph()
            self._list_stack.append([tag, 0])
            return
        if tag == "li":
            self._flush_paragraph()
            return
        if tag in _HEADING_BASE:
            self._flush_paragraph()
            return
        if tag == "blockquote":
            self._flush_paragraph()
            self._in_blockquote = True
            return
        if tag == "table":
            self._flush_paragraph()
            self._in_table = True
            self._table_rows = []
            return
        if tag in ("thead", "tbody"):
            return
        if tag == "tr":
            self._table_row_cells = []
            self._table_row_is_header = False
            return
        if tag in ("th", "td"):
            self._table_cell_buf = []
            if tag == "th":
                self._table_row_is_header = True
            return
        die("unhandled start tag <%s> reached builder (schema drift)" % tag)

    # -- end tags -------------------------------------------------------
    def end(self, tag):
        if tag in _LITERAL_TAGS:
            self._emit_text("</%s>" % tag)
            return
        # Mirror the start-tag suppression of inline markup inside <pre>.
        # (</pre> itself is not in _INLINE_TAGS, so it is handled normally.)
        if self._in_pre and tag in _INLINE_TAGS:
            return
        if tag == "br":
            return
        if tag == "p":
            self._flush_paragraph()
            return
        if tag == "em":
            self._emit_inline("*")
            return
        if tag == "strong":
            self._emit_inline("**")
            return
        if tag == "code":
            self._emit_inline("`")
            return
        if tag == "a":
            href_stack = getattr(self, "_a_href_stack", [])
            href = href_stack.pop() if href_stack else ""
            self._emit_inline("](%s)" % href)
            return
        if tag == "pre":
            code = "".join(self._pre_buf)
            code = code.strip("\n")
            self._in_pre = False
            self._pre_buf = []
            self._add_block("```php\n" + code + "\n```")
            return
        if tag in ("ul", "ol"):
            if self._list_stack:
                self._list_stack.pop()
            return
        if tag == "li":
            text = self._take_inline()
            if not self._list_stack:
                # Defensive: <li> outside a list -> treat as bullet.
                self._add_block("- " + text)
                return
            kind, counter = self._list_stack[-1]
            counter += 1
            self._list_stack[-1][1] = counter
            depth = len(self._list_stack) - 1
            indent = "  " * depth
            marker = "- " if kind == "ul" else ("%d. " % counter)
            # Indent continuation lines of multi-line items.
            lines = text.split("\n")
            rendered = indent + marker + lines[0]
            cont_indent = indent + " " * len(marker)
            for ln in lines[1:]:
                rendered += "\n" + cont_indent + ln
            self._add_block(rendered)
            return
        if tag in _HEADING_BASE:
            text = self._take_inline()
            level = _HEADING_BASE[tag] + self._heading_shift
            level = max(1, min(level, 6))
            self._add_block("#" * level + " " + text)
            return
        if tag == "blockquote":
            self._flush_paragraph()
            self._in_blockquote = False
            return
        if tag == "table":
            self._flush_paragraph()
            self._add_block(self._render_table())
            self._in_table = False
            self._table_rows = []
            return
        if tag in ("thead", "tbody"):
            return
        if tag == "tr":
            if self._table_row_cells is not None:
                self._table_rows.append(
                    (self._table_row_is_header, self._table_row_cells)
                )
            self._table_row_cells = None
            return
        if tag in ("th", "td"):
            cell = "".join(self._table_cell_buf)
            cell = re.sub(r"[ \t]*\n[ \t]*", " ", cell)
            cell = re.sub(r"[ \t]{2,}", " ", cell).strip()
            cell = cell.replace("|", "\\|")
            if self._table_row_cells is not None:
                self._table_row_cells.append(cell)
            self._table_cell_buf = None
            return
        die("unhandled end tag </%s> reached builder (schema drift)" % tag)

    def _render_table(self):
        if not self._table_rows:
            return ""
        header = None
        body = []
        for is_header, cells in self._table_rows:
            if is_header and header is None:
                header = cells
            else:
                body.append(cells)
        if header is None:
            # No <th>: synthesize a blank header from the widest row.
            width = max(len(c) for _, c in self._table_rows)
            header = [""] * width
            body = [c for _, c in self._table_rows]
        width = len(header)
        for c in body:
            width = max(width, len(c))

        def row(cells):
            padded = cells + [""] * (width - len(cells))
            return "| " + " | ".join(padded) + " |"

        out = [row(header), "| " + " | ".join(["---"] * width) + " |"]
        out.extend(row(c) for c in body)
        return "\n".join(out)

    def result(self):
        self._flush_paragraph()
        return "\n\n".join(b for b in self._blocks if b != "")


class _HTMLToMarkdown(HTMLParser):
    """Streams HTML events into a _MarkdownBuilder, aborting on unknown tags."""

    def __init__(self, heading_shift, context):
        super().__init__(convert_charrefs=True)
        self._builder = _MarkdownBuilder(heading_shift)
        self._context = context

    def handle_starttag(self, tag, attrs):
        if tag not in _ALL_KNOWN_TAGS:
            die("unknown HTML start tag <%s> in %s (handle it or it is schema "
                "drift)" % (tag, self._context))
        self._builder.start(tag, attrs)

    def handle_startendtag(self, tag, attrs):
        if tag not in _ALL_KNOWN_TAGS:
            die("unknown HTML self-closing tag <%s/> in %s" % (tag, self._context))
        # Only void/self-closing meaningful one here is <br>.
        self._builder.start(tag, attrs)
        if tag not in ("br",) and tag not in _LITERAL_TAGS:
            self._builder.end(tag)

    def handle_endtag(self, tag):
        if tag not in _ALL_KNOWN_TAGS:
            die("unknown HTML end tag </%s> in %s" % (tag, self._context))
        self._builder.end(tag)

    def handle_data(self, data):
        self._builder._emit_text(data)

    def result(self):
        return self._builder.result()


def html_to_markdown(source, heading_shift=0, context="<unknown>"):
    """Convert an HTML fragment (phpdoc long_description / inline desc) to
    Markdown. `convert_charrefs=True` means entities are already decoded by the
    parser before handle_data, so &amp;/&lt;/&gt; come through correctly."""
    if source is None:
        return ""
    source = source.strip()
    if not source:
        return ""
    parser = _HTMLToMarkdown(heading_shift, context)
    parser.feed(source)
    parser.close()
    return parser.result()


def inline_html_to_text(source, context="<inline>"):
    """Convert a short HTML fragment (description / @param content) to inline
    Markdown text. Multi-paragraph results are joined with blank lines, which is
    fine for the short prose these fields contain. Hash-notation @param blocks
    pass through with their @type lines intact (only <br>/<code> are markup)."""
    md = html_to_markdown(source, heading_shift=0, context=context)
    return md


# ---------------------------------------------------------------------------
# Signature construction
# ---------------------------------------------------------------------------

def _param_types_by_var(method_tags):
    """Map $variable -> 'type|type' from @param tags, preserving order."""
    mapping = {}
    for tag in method_tags:
        if tag.get("name") == "param":
            var = tag.get("variable") or ""
            types = tag.get("types") or []
            if var:
                mapping[var] = "|".join(types)
    return mapping


def _return_type(method_tags):
    for tag in method_tags:
        if tag.get("name") == "return":
            types = tag.get("types") or []
            if types:
                return "|".join(types)
    return ""


def build_signature(method):
    parts = []
    if method.get("final"):
        parts.append("final")
    if method.get("abstract"):
        parts.append("abstract")
    vis = method.get("visibility") or "public"
    parts.append(vis)
    if method.get("static"):
        parts.append("static")
    parts.append("function")

    tags = (method.get("doc") or {}).get("tags") or []
    types_by_var = _param_types_by_var(tags)

    args = []
    for arg in method.get("arguments") or []:
        name = arg.get("name") or ""
        typ = types_by_var.get(name) or (arg.get("type") or "")
        default = arg.get("default")
        piece = ""
        if typ:
            piece += typ + " "
        piece += name
        if default not in (None, ""):
            piece += " = " + default
        args.append(piece)

    ret = _return_type(tags)
    sig = " ".join(parts) + " " + (method.get("name") or "") + "(" + ", ".join(args) + ")"
    if ret:
        sig += ": " + ret
    return sig


# ---------------------------------------------------------------------------
# Markdown emission
# ---------------------------------------------------------------------------

class Out:
    def __init__(self):
        self._parts = []

    def line(self, text=""):
        self._parts.append(text)

    def block(self, text):
        if text:
            self._parts.append(text)

    def text(self):
        # Join with newlines; collapse 3+ blank lines to 2.
        raw = "\n".join(self._parts)
        raw = re.sub(r"\n{3,}", "\n\n", raw)
        return raw.rstrip() + "\n"


def md_escape_cell(text):
    return text.replace("|", "\\|").replace("\n", " ")


def render_tags_block(out, tags, exclude=("ignore",)):
    """Render the trailing doc tags (since/param/return/see/throws/etc.)."""
    # Group while preserving order of first appearance.
    since = [t for t in tags if t.get("name") == "since"]
    params = [t for t in tags if t.get("name") == "param"]
    returns = [t for t in tags if t.get("name") == "return"]
    sees = [t for t in tags if t.get("name") == "see"]
    throws = [t for t in tags if t.get("name") == "throws"]
    handled = {"since", "param", "return", "see", "throws"} | set(exclude)
    others = [t for t in tags if t.get("name") not in handled]

    if since:
        out.line("**Since:**")
        out.line()
        for t in since:
            ver = t.get("content") or ""
            desc = t.get("description") or ""
            if desc:
                out.line("- `%s` - %s" % (ver, desc))
            else:
                out.line("- `%s`" % ver)
        out.line()

    if params:
        out.line("**Parameters:**")
        out.line()
        out.line("| Parameter | Type | Description |")
        out.line("| --- | --- | --- |")
        for t in params:
            var = t.get("variable") or ""
            types = "|".join(t.get("types") or [])
            content = inline_html_to_text(t.get("content") or "", context="@param %s" % var)
            out.line("| `%s` | `%s` | %s |" % (
                md_escape_cell(var), md_escape_cell(types), md_escape_cell(content)))
        out.line()

    if returns:
        out.line("**Returns:**")
        out.line()
        for t in returns:
            types = "|".join(t.get("types") or [])
            content = inline_html_to_text(t.get("content") or "", context="@return")
            if types and content:
                out.line("- `%s` - %s" % (types, content))
            elif types:
                out.line("- `%s`" % types)
            elif content:
                out.line("- %s" % content)
        out.line()

    if throws:
        out.line("**Throws:**")
        out.line()
        for t in throws:
            types = "|".join(t.get("types") or [])
            content = inline_html_to_text(t.get("content") or "", context="@throws")
            if types and content:
                out.line("- `%s` - %s" % (types, content))
            elif types:
                out.line("- `%s`" % types)
            elif content:
                out.line("- %s" % content)
        out.line()

    if sees:
        out.line("**See:**")
        out.line()
        for t in sees:
            refers = t.get("refers") or ""
            content = inline_html_to_text(t.get("content") or "", context="@see")
            if refers and content:
                out.line("- `%s` - %s" % (refers, content))
            elif refers:
                out.line("- `%s`" % refers)
            elif content:
                out.line("- %s" % content)
        out.line()

    if others:
        out.line("**Other tags:**")
        out.line()
        for t in others:
            name = t.get("name") or ""
            content = inline_html_to_text(t.get("content") or "", context="@%s" % name)
            types = "|".join(t.get("types") or [])
            bits = []
            if types:
                bits.append("`%s`" % types)
            if content:
                bits.append(content)
            suffix = (" " + " - ".join(bits)) if bits else ""
            out.line("- `@%s`%s" % (name, suffix))
        out.line()


def render_class(out, file_obj, cls):
    name = cls.get("name") or ""
    namespace = cls.get("namespace") or ""

    # 1. H1 + file-level description.
    out.line("# %s" % name)
    out.line()
    file_meta = file_obj.get("file") or {}
    fdesc = (file_meta.get("description") or "").strip()
    if fdesc:
        out.line(inline_html_to_text(fdesc, context="file.description"))
        out.line()
    fld = (file_meta.get("long_description") or "").strip()
    if fld:
        out.block(html_to_markdown(fld, heading_shift=1, context="file.long_description"))
        out.line()

    # 2. Class overview.
    out.line("## Overview")
    out.line()
    doc = cls.get("doc") or {}
    cdesc = (doc.get("description") or "").strip()
    if cdesc:
        out.line(inline_html_to_text(cdesc, context="class.description"))
        out.line()
    cld = (doc.get("long_description") or "").strip()
    if cld:
        out.block(html_to_markdown(cld, heading_shift=1, context="class.long_description"))
        out.line()

    meta_lines = []
    if namespace and namespace not in ("", "\\"):
        meta_lines.append("- **Namespace:** `%s`" % namespace)
    if cls.get("extends"):
        meta_lines.append("- **Extends:** `%s`" % cls.get("extends"))
    impl = cls.get("implements") or []
    if impl:
        meta_lines.append("- **Implements:** %s" % ", ".join("`%s`" % i for i in impl))
    if cls.get("final"):
        meta_lines.append("- **Final:** yes")
    if cls.get("abstract"):
        meta_lines.append("- **Abstract:** yes")
    if meta_lines:
        for ln in meta_lines:
            out.line(ln)
        out.line()

    # Class-level tags (since/see/etc.), excluding noise.
    class_tags = [t for t in (doc.get("tags") or [])
                  if t.get("name") not in ("ignore",)]
    if class_tags:
        render_tags_block(out, class_tags)

    methods = cls.get("methods") or []
    properties = cls.get("properties") or []

    # 3. Method index.
    if methods:
        out.line("## Method Index")
        out.line()
        out.line("| Method | Visibility | Description |")
        out.line("| --- | --- | --- |")
        for m in methods:
            mname = m.get("name") or ""
            vis = m.get("visibility") or "public"
            extra = []
            if m.get("static"):
                extra.append("static")
            if m.get("abstract"):
                extra.append("abstract")
            if m.get("final"):
                extra.append("final")
            vis_label = vis + ((" " + " ".join(extra)) if extra else "")
            mdesc = inline_html_to_text((m.get("doc") or {}).get("description") or "",
                                        context="method %s description" % mname)
            anchor = mname.lstrip("_") or mname
            out.line("| [`%s`](#%s) | %s | %s |" % (
                mname, _anchor(mname), md_escape_cell(vis_label), md_escape_cell(mdesc)))
        out.line()

    # 4. Properties.
    if properties:
        out.line("## Properties")
        out.line()
        for p in properties:
            pname = p.get("name") or ""
            # phpdoc-parser property names already carry the leading "$".
            pname_bare = pname.lstrip("$")
            vis = p.get("visibility") or "public"
            pdoc = p.get("doc") or {}
            ptags = pdoc.get("tags") or []
            ptype = ""
            for t in ptags:
                if t.get("name") == "var":
                    ptype = "|".join(t.get("types") or [])
                    break
            static = " static" if p.get("static") else ""
            out.line("### `$%s`" % pname_bare)
            out.line()
            sig_bits = [vis.strip() + static]
            if ptype:
                sig_bits.append(ptype)
            header = " ".join(b for b in sig_bits if b)
            default = p.get("default")
            decl = "%s $%s" % (header, pname_bare)
            if default not in (None, ""):
                decl += " = " + str(default)
            out.line("```php")
            out.line(decl + ";")
            out.line("```")
            out.line()
            pdesc = (pdoc.get("description") or "").strip()
            if pdesc:
                out.line(inline_html_to_text(pdesc, context="property %s" % pname))
                out.line()
            pld = (pdoc.get("long_description") or "").strip()
            if pld:
                out.block(html_to_markdown(pld, heading_shift=2,
                                           context="property %s long_description" % pname))
                out.line()
            # Property since/see etc. (skip the @var we already used, and noise).
            rest = [t for t in ptags if t.get("name") not in ("var", "ignore")]
            if rest:
                render_tags_block(out, rest)

    # 5. Methods.
    if methods:
        out.line("## Methods")
        out.line()
        for m in methods:
            render_method(out, m)


def _anchor(name):
    """GitHub-style anchor for a method heading like '### `name()`'."""
    text = name + "()"
    text = text.lower()
    text = re.sub(r"[^a-z0-9 _-]", "", text)
    text = text.replace(" ", "-")
    return text


def render_method(out, method):
    mname = method.get("name") or ""
    out.line("### `%s()`" % mname)
    out.line()
    out.line("```php")
    out.line(build_signature(method))
    out.line("```")
    out.line()

    doc = method.get("doc") or {}
    mdesc = (doc.get("description") or "").strip()
    if mdesc:
        out.line(inline_html_to_text(mdesc, context="method %s description" % mname))
        out.line()
    mld = (doc.get("long_description") or "").strip()
    if mld:
        out.block(html_to_markdown(mld, heading_shift=2,
                                   context="method %s long_description" % mname))
        out.line()

    aliases = method.get("aliases") or []
    if aliases:
        out.line("**Aliases:** %s" % ", ".join("`%s`" % a for a in aliases))
        out.line()

    tags = [t for t in (doc.get("tags") or []) if t.get("name") not in ("ignore",)]
    if tags:
        render_tags_block(out, tags)


def render_document(data):
    out = Out()
    if not isinstance(data, list):
        die("top-level JSON is not an array (got %s)" % type(data).__name__)
    for i, file_obj in enumerate(data):
        classes = file_obj.get("classes") or []
        if not classes:
            continue
        for j, cls in enumerate(classes):
            if i + j > 0:
                out.line()
                out.line("---")
                out.line()
            render_class(out, file_obj, cls)
    return out.text()


def main(argv):
    ap = argparse.ArgumentParser(
        description="Render phpdoc-parser JSON to Markdown (deterministic).")
    ap.add_argument("-i", "--input", required=True, help="Input JSON file.")
    ap.add_argument("-o", "--output", required=True, help="Output Markdown file.")
    args = ap.parse_args(argv)

    with open(args.input, "r", encoding="utf-8") as fh:
        data = json.load(fh)

    markdown = render_document(data)

    with open(args.output, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(markdown)

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
