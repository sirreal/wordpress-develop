/*
 * Source-built Lexbor tree oracle for the HTML API fuzzer.
 *
 * Parses one input with Lexbor and emits a JSON result whose "tree" field uses
 * the same html5lib-style text format as HtmlApiFuzz\TreeRenderer.
 */

#include <ctype.h>
#include <errno.h>
#include <stdbool.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include <lexbor/dom/interfaces/character_data.h>
#include <lexbor/dom/interfaces/document_type.h>
#include <lexbor/html/html.h>
#include <lexbor/ns/const.h>

#ifndef HTML_API_FUZZ_LEXBOR_COMMIT
#define HTML_API_FUZZ_LEXBOR_COMMIT "unknown"
#endif

typedef struct {
	char *data;
	size_t length;
	size_t capacity;
	bool failed;
} buffer_t;

typedef struct {
	char *sort_name;
	char *render_name;
	char *value;
} attr_record_t;

typedef enum {
	ORACLE_OK,
	ORACLE_UNSUPPORTED,
	ORACLE_ERROR,
} oracle_status_t;

typedef struct {
	oracle_status_t status;
	const char *failure_class;
	const char *message;
	buffer_t tree;
	size_t node_count;
	size_t max_nodes;
} render_ctx_t;

typedef struct {
	const char *mode;
	const char *context;
	const char *input_path;
	size_t max_nodes;
	bool show_help;
	bool show_version;
} cli_options_t;

static void buffer_init(buffer_t *buf);
static void buffer_destroy(buffer_t *buf);
static bool buffer_reserve(buffer_t *buf, size_t extra);
static bool buffer_append_mem(buffer_t *buf, const char *data, size_t len);
static bool buffer_append_cstr(buffer_t *buf, const char *data);
static bool buffer_append_char(buffer_t *buf, char ch);
static bool buffer_append_repeat(buffer_t *buf, const char *data, size_t len, size_t count);
static char *buffer_take_cstr(buffer_t *buf);
static bool append_escaped_scalar(buffer_t *buf, const lxb_char_t *data, size_t len, bool scrub);
static size_t valid_utf8_sequence_length(const char *data, size_t len, size_t offset);
static bool valid_utf8(const char *data, size_t len);
static bool append_json_string(buffer_t *buf, const char *data, size_t len);
static bool append_json_base64(buffer_t *buf, const char *data, size_t len);
static bool append_tree_line_indent(buffer_t *buf, int indent_level);
static bool append_display_element_name(buffer_t *buf, lxb_dom_element_t *element);
static bool append_escaped_display_element_name(buffer_t *buf, lxb_dom_element_t *element);
static bool append_display_attribute_name(buffer_t *buf, lxb_dom_attr_t *attr);
static int compare_attr_records(const void *a_ptr, const void *b_ptr);
static bool render_attributes(render_ctx_t *ctx, lxb_dom_element_t *element, int indent_level);
static void destroy_attr_records(attr_record_t *records, size_t count);
static void render_node(render_ctx_t *ctx, lxb_dom_node_t *node, int indent_level);
static void render_children(render_ctx_t *ctx, lxb_dom_node_t *first, int indent_level);
static bool read_file(const char *path, lxb_char_t **data, size_t *len, const char **message);
static bool parse_size(const char *value, size_t *out);
static bool parse_args(int argc, char **argv, cli_options_t *options, const char **message);
static void print_usage(FILE *stream);
static void print_version(void);
static void print_result(render_ctx_t *ctx);
static void print_cli_error(const char *message);
static bool context_to_tag(const char *context, lxb_tag_id_t *tag_id, lxb_ns_id_t *ns_id);
static void render_full_document(render_ctx_t *ctx, const lxb_char_t *input, size_t input_len);
static void render_fragment(render_ctx_t *ctx, const lxb_char_t *input, size_t input_len, const char *context);

static void
buffer_init(buffer_t *buf)
{
	buf->data = NULL;
	buf->length = 0;
	buf->capacity = 0;
	buf->failed = false;
}

static void
buffer_destroy(buffer_t *buf)
{
	free(buf->data);
	buffer_init(buf);
}

static bool
buffer_reserve(buffer_t *buf, size_t extra)
{
	size_t needed;
	size_t next_capacity;
	char *next;

	if (buf->failed) {
		return false;
	}

	if (extra > SIZE_MAX - buf->length - 1) {
		buf->failed = true;
		return false;
	}

	needed = buf->length + extra + 1;
	if (needed <= buf->capacity) {
		return true;
	}

	next_capacity = buf->capacity == 0 ? 256 : buf->capacity;
	while (next_capacity < needed) {
		if (next_capacity > SIZE_MAX / 2) {
			next_capacity = needed;
			break;
		}
		next_capacity *= 2;
	}

	next = (char *) realloc(buf->data, next_capacity);
	if (next == NULL) {
		buf->failed = true;
		return false;
	}

	buf->data = next;
	buf->capacity = next_capacity;
	buf->data[buf->length] = '\0';
	return true;
}

static bool
buffer_append_mem(buffer_t *buf, const char *data, size_t len)
{
	if (!buffer_reserve(buf, len)) {
		return false;
	}

	if (len > 0) {
		memcpy(buf->data + buf->length, data, len);
		buf->length += len;
	}

	buf->data[buf->length] = '\0';
	return true;
}

static bool
buffer_append_cstr(buffer_t *buf, const char *data)
{
	return buffer_append_mem(buf, data, strlen(data));
}

static bool
buffer_append_char(buffer_t *buf, char ch)
{
	return buffer_append_mem(buf, &ch, 1);
}

static bool
buffer_append_repeat(buffer_t *buf, const char *data, size_t len, size_t count)
{
	size_t i;

	for (i = 0; i < count; i++) {
		if (!buffer_append_mem(buf, data, len)) {
			return false;
		}
	}

	return true;
}

static char *
buffer_take_cstr(buffer_t *buf)
{
	char *data;

	if (!buffer_reserve(buf, 0)) {
		return NULL;
	}

	data = buf->data;
	buf->data = NULL;
	buf->length = 0;
	buf->capacity = 0;
	return data;
}

static bool
append_escaped_byte(buffer_t *buf, unsigned char byte)
{
	char hex[5];

	switch (byte) {
		case '\n':
			return buffer_append_cstr(buf, "\\n");
		case '\r':
			return buffer_append_cstr(buf, "\\r");
		case '\t':
			return buffer_append_cstr(buf, "\\t");
		case '\0':
			return buffer_append_cstr(buf, "\\0");
		case '\\':
			return buffer_append_cstr(buf, "\\\\");
		case '"':
			return buffer_append_cstr(buf, "\\\"");
		default:
			if (byte < 0x20 || byte == 0x7f) {
				snprintf(hex, sizeof(hex), "\\x%02X", byte);
				return buffer_append_cstr(buf, hex);
			}
			return buffer_append_char(buf, (char) byte);
	}
}

static bool
append_escaped_scalar(buffer_t *buf, const lxb_char_t *data, size_t len, bool scrub)
{
	size_t i;
	static const char replacement[] = "\xEF\xBF\xBD";

	if (data == NULL) {
		len = 0;
	}

	for (i = 0; i < len; i++) {
		unsigned char byte = (unsigned char) data[i];

		if (scrub) {
			if (byte == '\0') {
				if (!buffer_append_mem(buf, replacement, sizeof(replacement) - 1)) {
					return false;
				}
				continue;
			}

			if (byte == '\r') {
				if (i + 1 < len && data[i + 1] == '\n') {
					i++;
				}
				byte = '\n';
			}
		}

		if (!append_escaped_byte(buf, byte)) {
			return false;
		}
	}

	return true;
}

static bool
valid_utf8(const char *data, size_t len)
{
	size_t offset = 0;

	while (offset < len) {
		size_t sequence_len = valid_utf8_sequence_length(data, len, offset);
		if (sequence_len == 0) {
			return false;
		}
		offset += sequence_len;
	}

	return true;
}

static size_t
valid_utf8_sequence_length(const char *data, size_t len, size_t offset)
{
	unsigned char byte;
	unsigned char b1;
	unsigned char b2;
	unsigned char b3;
	size_t sequence_len;

	if (offset >= len) {
		return 0;
	}
	byte = (unsigned char) data[offset];
	if (byte < 0x80) {
		return 1;
	}
	if (byte >= 0xC2 && byte <= 0xDF) {
		sequence_len = 2;
	} else if (byte >= 0xE0 && byte <= 0xEF) {
		sequence_len = 3;
	} else if (byte >= 0xF0 && byte <= 0xF4) {
		sequence_len = 4;
	} else {
		return 0;
	}
	if (offset + sequence_len > len) {
		return 0;
	}

	b1 = (unsigned char) data[offset + 1];
	b2 = sequence_len > 2 ? (unsigned char) data[offset + 2] : 0;
	b3 = sequence_len > 3 ? (unsigned char) data[offset + 3] : 0;
	if (b1 < 0x80 || b1 > 0xBF) {
		return 0;
	}
	if (sequence_len > 2 && (b2 < 0x80 || b2 > 0xBF)) {
		return 0;
	}
	if (sequence_len > 3 && (b3 < 0x80 || b3 > 0xBF)) {
		return 0;
	}
	if (byte == 0xE0 && b1 < 0xA0) {
		return 0;
	}
	if (byte == 0xED && b1 > 0x9F) {
		return 0;
	}
	if (byte == 0xF0 && b1 < 0x90) {
		return 0;
	}
	if (byte == 0xF4 && b1 > 0x8F) {
		return 0;
	}

	return sequence_len;
}

static bool
append_json_string(buffer_t *buf, const char *data, size_t len)
{
	size_t i;
	char hex[7];
	static const char replacement[] = "\\uFFFD";

	if (!buffer_append_char(buf, '"')) {
		return false;
	}

	for (i = 0; i < len; i++) {
		unsigned char byte = (unsigned char) data[i];

		switch (byte) {
			case '"':
				if (!buffer_append_cstr(buf, "\\\"")) {
					return false;
				}
				break;
			case '\\':
				if (!buffer_append_cstr(buf, "\\\\")) {
					return false;
				}
				break;
			case '\b':
				if (!buffer_append_cstr(buf, "\\b")) {
					return false;
				}
				break;
			case '\f':
				if (!buffer_append_cstr(buf, "\\f")) {
					return false;
				}
				break;
			case '\n':
				if (!buffer_append_cstr(buf, "\\n")) {
					return false;
				}
				break;
			case '\r':
				if (!buffer_append_cstr(buf, "\\r")) {
					return false;
				}
				break;
			case '\t':
				if (!buffer_append_cstr(buf, "\\t")) {
					return false;
				}
				break;
			default:
				if (byte < 0x20) {
					snprintf(hex, sizeof(hex), "\\u%04X", byte);
					if (!buffer_append_cstr(buf, hex)) {
						return false;
					}
				} else if (byte < 0x80) {
					if (!buffer_append_char(buf, (char) byte)) {
						return false;
					}
				} else {
					size_t sequence_len = valid_utf8_sequence_length(data, len, i);

					if (sequence_len > 0) {
						if (!buffer_append_mem(buf, data + i, sequence_len)) {
							return false;
						}
						i += sequence_len - 1;
					} else if (!buffer_append_cstr(buf, replacement)) {
						return false;
					}
				}
				break;
		}
	}

	return buffer_append_char(buf, '"');
}

static bool
append_json_base64(buffer_t *buf, const char *data, size_t len)
{
	static const char alphabet[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
	size_t i;

	if (!buffer_append_char(buf, '"')) {
		return false;
	}

	for (i = 0; i < len; i += 3) {
		unsigned int b0 = (unsigned char) data[i];
		unsigned int b1 = i + 1 < len ? (unsigned char) data[i + 1] : 0;
		unsigned int b2 = i + 2 < len ? (unsigned char) data[i + 2] : 0;
		char encoded[4];

		encoded[0] = alphabet[b0 >> 2];
		encoded[1] = alphabet[((b0 & 0x03) << 4) | (b1 >> 4)];
		encoded[2] = i + 1 < len ? alphabet[((b1 & 0x0F) << 2) | (b2 >> 6)] : '=';
		encoded[3] = i + 2 < len ? alphabet[b2 & 0x3F] : '=';

		if (!buffer_append_mem(buf, encoded, sizeof(encoded))) {
			return false;
		}
	}

	return buffer_append_char(buf, '"');
}

static bool
append_tree_line_indent(buffer_t *buf, int indent_level)
{
	return buffer_append_repeat(buf, "  ", 2, (size_t) indent_level);
}

static bool
append_ascii_lower(buffer_t *buf, const lxb_char_t *data, size_t len)
{
	size_t i;

	for (i = 0; i < len; i++) {
		unsigned char byte = (unsigned char) data[i];
		if (byte >= 'A' && byte <= 'Z') {
			byte = (unsigned char) tolower(byte);
		}
		if (!buffer_append_char(buf, (char) byte)) {
			return false;
		}
	}

	return true;
}

static bool
append_display_element_name(buffer_t *buf, lxb_dom_element_t *element)
{
	size_t len = 0;
	const lxb_char_t *name;
	lxb_ns_id_t ns = lxb_dom_element_ns_id(element);

	name = lxb_dom_element_local_name(element, &len);
	if (ns == LXB_NS_HTML) {
		return append_ascii_lower(buf, name, len);
	}

	if (ns == LXB_NS_SVG) {
		name = lxb_dom_element_qualified_name(element, &len);
		return buffer_append_cstr(buf, "svg ") && buffer_append_mem(buf, (const char *) name, len);
	}

	if (ns == LXB_NS_MATH) {
		return buffer_append_cstr(buf, "math ") && buffer_append_mem(buf, (const char *) name, len);
	}

	name = lxb_dom_element_qualified_name(element, &len);
	return buffer_append_mem(buf, (const char *) name, len);
}

static bool
append_escaped_display_element_name(buffer_t *buf, lxb_dom_element_t *element)
{
	buffer_t display;
	bool ok;

	buffer_init(&display);
	ok = append_display_element_name(&display, element)
		&& append_escaped_scalar(buf, (const lxb_char_t *) display.data, display.length, false);
	buffer_destroy(&display);

	return ok;
}

static bool
append_display_attribute_name(buffer_t *buf, lxb_dom_attr_t *attr)
{
	size_t len = 0;
	const lxb_char_t *name;
	lxb_ns_id_t ns = (lxb_ns_id_t) lxb_dom_interface_node(attr)->ns;

	if (ns == LXB_NS_XLINK) {
		name = lxb_dom_attr_local_name(attr, &len);
		return buffer_append_cstr(buf, "xlink ") && buffer_append_mem(buf, (const char *) name, len);
	}

	if (ns == LXB_NS_XML) {
		name = lxb_dom_attr_local_name(attr, &len);
		return buffer_append_cstr(buf, "xml ") && buffer_append_mem(buf, (const char *) name, len);
	}

	if (ns == LXB_NS_XMLNS) {
		name = lxb_dom_attr_local_name(attr, &len);
		return buffer_append_cstr(buf, "xmlns ") && buffer_append_mem(buf, (const char *) name, len);
	}

	name = lxb_dom_attr_qualified_name(attr, &len);
	return buffer_append_mem(buf, (const char *) name, len);
}

static int
compare_display_names(const char *a, const char *b)
{
	bool a_has_colon = strchr(a, ':') != NULL;
	bool b_has_colon = strchr(b, ':') != NULL;
	bool a_has_space = strchr(a, ' ') != NULL;
	bool b_has_space = strchr(b, ' ') != NULL;
	int compared;

	if (a_has_colon != b_has_colon) {
		return a_has_colon ? 1 : -1;
	}

	if (a_has_space != b_has_space) {
		return a_has_space ? 1 : -1;
	}

	compared = strcmp(a, b);
	if (compared < 0) {
		return -1;
	}
	if (compared > 0) {
		return 1;
	}
	return 0;
}

static int
compare_attr_records(const void *a_ptr, const void *b_ptr)
{
	const attr_record_t *a = (const attr_record_t *) a_ptr;
	const attr_record_t *b = (const attr_record_t *) b_ptr;
	int compared = compare_display_names(a->sort_name, b->sort_name);

	if (compared != 0) {
		return compared;
	}

	return compare_display_names(a->render_name, b->render_name);
}

static bool
render_attributes(render_ctx_t *ctx, lxb_dom_element_t *element, int indent_level)
{
	lxb_dom_attr_t *attr;
	attr_record_t *records = NULL;
	size_t count = 0;
	size_t index = 0;
	size_t i;
	bool ok = false;

	for (attr = lxb_dom_element_first_attribute(element); attr != NULL; attr = lxb_dom_element_next_attribute(attr)) {
		count++;
	}

	if (count == 0) {
		return true;
	}

	records = (attr_record_t *) calloc(count, sizeof(attr_record_t));
	if (records == NULL) {
		ctx->status = ORACLE_ERROR;
		ctx->failure_class = "oracle-renderer-error";
		ctx->message = "Could not allocate attribute records.";
		return false;
	}

	for (attr = lxb_dom_element_first_attribute(element); attr != NULL; attr = lxb_dom_element_next_attribute(attr)) {
		buffer_t display;
		buffer_t sort;
		buffer_t render;
		buffer_t value;
		size_t value_len = 0;
		const lxb_char_t *value_data;

		buffer_init(&display);
		buffer_init(&sort);
		buffer_init(&render);
		buffer_init(&value);

		value_data = lxb_dom_attr_value(attr, &value_len);
		if (
			!append_display_attribute_name(&display, attr) ||
			!append_escaped_scalar(&sort, (const lxb_char_t *) display.data, display.length, true) ||
			!append_escaped_scalar(&render, (const lxb_char_t *) display.data, display.length, false) ||
			!append_escaped_scalar(&value, value_data, value_len, false)
		) {
			buffer_destroy(&display);
			buffer_destroy(&sort);
			buffer_destroy(&render);
			buffer_destroy(&value);
			ctx->status = ORACLE_ERROR;
			ctx->failure_class = "oracle-renderer-error";
			ctx->message = "Could not render attributes.";
			goto cleanup;
		}

		records[index].sort_name = buffer_take_cstr(&sort);
		records[index].render_name = buffer_take_cstr(&render);
		records[index].value = buffer_take_cstr(&value);

		buffer_destroy(&display);
		buffer_destroy(&sort);
		buffer_destroy(&render);
		buffer_destroy(&value);

		if (records[index].sort_name == NULL || records[index].render_name == NULL || records[index].value == NULL) {
			ctx->status = ORACLE_ERROR;
			ctx->failure_class = "oracle-renderer-error";
			ctx->message = "Could not store attribute records.";
			goto cleanup;
		}

		index++;
	}

	qsort(records, count, sizeof(attr_record_t), compare_attr_records);

	for (i = 0; i < count; i++) {
		if (
			!append_tree_line_indent(&ctx->tree, indent_level) ||
			!buffer_append_cstr(&ctx->tree, records[i].render_name) ||
			!buffer_append_cstr(&ctx->tree, "=\"") ||
			!buffer_append_cstr(&ctx->tree, records[i].value) ||
			!buffer_append_cstr(&ctx->tree, "\"\n")
		) {
			ctx->status = ORACLE_ERROR;
			ctx->failure_class = "oracle-renderer-error";
			ctx->message = "Could not append attribute lines.";
			goto cleanup;
		}
	}

	ok = true;

cleanup:
	destroy_attr_records(records, count);
	return ok;
}

static void
destroy_attr_records(attr_record_t *records, size_t count)
{
	size_t i;

	if (records == NULL) {
		return;
	}

	for (i = 0; i < count; i++) {
		free(records[i].sort_name);
		free(records[i].render_name);
		free(records[i].value);
	}

	free(records);
}

static bool
increment_node_count(render_ctx_t *ctx)
{
	ctx->node_count++;
	if (ctx->node_count > ctx->max_nodes) {
		ctx->status = ORACLE_ERROR;
		ctx->failure_class = "node-limit-exceeded";
		ctx->message = "DOM node limit exceeded.";
		return false;
	}

	return true;
}

static void
render_node(render_ctx_t *ctx, lxb_dom_node_t *node, int indent_level)
{
	if (ctx->status != ORACLE_OK || node == NULL) {
		return;
	}

	if (!increment_node_count(ctx)) {
		return;
	}

	switch (node->type) {
		case LXB_DOM_NODE_TYPE_DOCUMENT_TYPE: {
			lxb_dom_document_type_t *doctype = lxb_dom_interface_document_type(node);
			size_t name_len = 0;
			size_t public_len = 0;
			size_t system_len = 0;
			const lxb_char_t *name = lxb_dom_document_type_name(doctype, &name_len);
			const lxb_char_t *public_id = lxb_dom_document_type_public_id(doctype, &public_len);
			const lxb_char_t *system_id = lxb_dom_document_type_system_id(doctype, &system_len);

			if (
				!buffer_append_cstr(&ctx->tree, "<!DOCTYPE ") ||
				!append_escaped_scalar(&ctx->tree, name, name_len, false)
			) {
				ctx->status = ORACLE_ERROR;
				ctx->failure_class = "oracle-renderer-error";
				ctx->message = "Could not render doctype.";
				return;
			}

			if (public_len > 0 || system_len > 0) {
				if (
					!buffer_append_cstr(&ctx->tree, " \"") ||
					!append_escaped_scalar(&ctx->tree, public_id, public_len, false) ||
					!buffer_append_cstr(&ctx->tree, "\" \"") ||
					!append_escaped_scalar(&ctx->tree, system_id, system_len, false) ||
					!buffer_append_char(&ctx->tree, '"')
				) {
					ctx->status = ORACLE_ERROR;
					ctx->failure_class = "oracle-renderer-error";
					ctx->message = "Could not render doctype identifiers.";
					return;
				}
			}

			if (!buffer_append_cstr(&ctx->tree, ">\n")) {
				ctx->status = ORACLE_ERROR;
				ctx->failure_class = "oracle-renderer-error";
				ctx->message = "Could not finish doctype.";
			}
			return;
		}

		case LXB_DOM_NODE_TYPE_ELEMENT: {
			lxb_dom_element_t *element = lxb_dom_interface_element(node);

			if (
				!append_tree_line_indent(&ctx->tree, indent_level) ||
				!buffer_append_char(&ctx->tree, '<') ||
				!append_escaped_display_element_name(&ctx->tree, element) ||
				!buffer_append_cstr(&ctx->tree, ">\n") ||
				!render_attributes(ctx, element, indent_level + 1)
			) {
				if (ctx->status == ORACLE_OK) {
					ctx->status = ORACLE_ERROR;
					ctx->failure_class = "oracle-renderer-error";
					ctx->message = "Could not render element.";
				}
				return;
			}

			if (node->local_name == LXB_TAG_TEMPLATE && node->ns == LXB_NS_HTML) {
				lxb_html_template_element_t *template_element = lxb_html_interface_template(node);
				if (!append_tree_line_indent(&ctx->tree, indent_level + 1) || !buffer_append_cstr(&ctx->tree, "content\n")) {
					ctx->status = ORACLE_ERROR;
					ctx->failure_class = "oracle-renderer-error";
					ctx->message = "Could not render template content marker.";
					return;
				}
				if (template_element->content != NULL) {
					render_children(ctx, template_element->content->node.first_child, indent_level + 2);
				}
				return;
			}

			render_children(ctx, node->first_child, indent_level + 1);
			return;
		}

		case LXB_DOM_NODE_TYPE_TEXT:
		case LXB_DOM_NODE_TYPE_CDATA_SECTION: {
			lxb_dom_character_data_t *character_data = lxb_dom_interface_character_data(node);
			if (character_data->data.length == 0) {
				return;
			}
			if (
				!append_tree_line_indent(&ctx->tree, indent_level) ||
				!buffer_append_char(&ctx->tree, '"') ||
				!append_escaped_scalar(&ctx->tree, character_data->data.data, character_data->data.length, false) ||
				!buffer_append_cstr(&ctx->tree, "\"\n")
			) {
				ctx->status = ORACLE_ERROR;
				ctx->failure_class = "oracle-renderer-error";
				ctx->message = "Could not render text.";
			}
			return;
		}

		case LXB_DOM_NODE_TYPE_COMMENT: {
			lxb_dom_character_data_t *character_data = lxb_dom_interface_character_data(node);
			if (
				!append_tree_line_indent(&ctx->tree, indent_level) ||
				!buffer_append_cstr(&ctx->tree, "<!-- ") ||
				!append_escaped_scalar(&ctx->tree, character_data->data.data, character_data->data.length, false) ||
				!buffer_append_cstr(&ctx->tree, " -->\n")
			) {
				ctx->status = ORACLE_ERROR;
				ctx->failure_class = "oracle-renderer-error";
				ctx->message = "Could not render comment.";
			}
			return;
		}

		default:
			return;
	}
}

static void
render_children(render_ctx_t *ctx, lxb_dom_node_t *first, int indent_level)
{
	lxb_dom_node_t *child;

	for (child = first; child != NULL && ctx->status == ORACLE_OK; child = child->next) {
		render_node(ctx, child, indent_level);
	}
}

static bool
read_file(const char *path, lxb_char_t **data, size_t *len, const char **message)
{
	FILE *file;
	long size;
	size_t read_len;
	lxb_char_t *bytes;

	file = fopen(path, "rb");
	if (file == NULL) {
		*message = strerror(errno);
		return false;
	}

	if (fseek(file, 0, SEEK_END) != 0) {
		fclose(file);
		*message = "Could not seek input file.";
		return false;
	}

	size = ftell(file);
	if (size < 0) {
		fclose(file);
		*message = "Could not determine input size.";
		return false;
	}

	if (fseek(file, 0, SEEK_SET) != 0) {
		fclose(file);
		*message = "Could not rewind input file.";
		return false;
	}

	bytes = (lxb_char_t *) malloc((size_t) size + 1);
	if (bytes == NULL) {
		fclose(file);
		*message = "Could not allocate input buffer.";
		return false;
	}

	read_len = fread(bytes, 1, (size_t) size, file);
	if (read_len != (size_t) size || ferror(file)) {
		free(bytes);
		fclose(file);
		*message = "Could not read input file.";
		return false;
	}

	fclose(file);
	bytes[read_len] = '\0';
	*data = bytes;
	*len = read_len;
	return true;
}

static bool
parse_size(const char *value, size_t *out)
{
	char *end = NULL;
	unsigned long parsed;

	errno = 0;
	parsed = strtoul(value, &end, 10);
	if (errno != 0 || end == value || *end != '\0' || parsed == 0) {
		return false;
	}

	*out = (size_t) parsed;
	return true;
}

static bool
parse_args(int argc, char **argv, cli_options_t *options, const char **message)
{
	int i;

	options->mode = NULL;
	options->context = "body";
	options->input_path = NULL;
	options->max_nodes = 3000;
	options->show_help = false;
	options->show_version = false;

	for (i = 1; i < argc; i++) {
		const char *arg = argv[i];

		if (strcmp(arg, "--help") == 0 || strcmp(arg, "-h") == 0) {
			options->show_help = true;
			return true;
		}
		if (strcmp(arg, "--version") == 0) {
			options->show_version = true;
			return true;
		}

		if (i + 1 >= argc) {
			*message = "Missing option value.";
			return false;
		}

		if (strcmp(arg, "--mode") == 0) {
			options->mode = argv[++i];
		} else if (strcmp(arg, "--context") == 0) {
			options->context = argv[++i];
		} else if (strcmp(arg, "--input") == 0) {
			options->input_path = argv[++i];
		} else if (strcmp(arg, "--max-nodes") == 0) {
			if (!parse_size(argv[++i], &options->max_nodes)) {
				*message = "Expected --max-nodes to be a positive integer.";
				return false;
			}
		} else {
			*message = "Unknown option.";
			return false;
		}
	}

	if (options->mode == NULL) {
		*message = "Missing --mode.";
		return false;
	}
	if (strcmp(options->mode, "full-document") != 0 && strcmp(options->mode, "fragment-body") != 0) {
		*message = "Expected --mode full-document or fragment-body.";
		return false;
	}
	if (options->input_path == NULL) {
		*message = "Missing --input.";
		return false;
	}

	return true;
}

static void
print_usage(FILE *stream)
{
	fprintf(
		stream,
		"Usage: lexbor-tree-oracle --mode full-document|fragment-body --input PATH [--context TAG] [--max-nodes N]\n"
	);
}

static void
print_version(void)
{
	printf(
		"{\"status\":\"ok\",\"oracle\":{\"kind\":\"lexbor-source\",\"available\":true,\"lexborCommit\":\"%s\",\"lexborVersion\":\"%s\"}}\n",
		HTML_API_FUZZ_LEXBOR_COMMIT,
		LXB_HTML_VERSION_STRING
	);
}

static void
print_result(render_ctx_t *ctx)
{
	buffer_t json;
	const char *status_text = ctx->status == ORACLE_OK
		? "ok"
		: (ctx->status == ORACLE_UNSUPPORTED ? "unsupported" : "error");

	buffer_init(&json);
	buffer_append_cstr(&json, "{\n  \"status\": ");
	append_json_string(&json, status_text, strlen(status_text));
	buffer_append_cstr(&json, ",\n  \"oracle\": {\n    \"kind\": \"lexbor-source\",\n    \"lexborCommit\": ");
	append_json_string(&json, HTML_API_FUZZ_LEXBOR_COMMIT, strlen(HTML_API_FUZZ_LEXBOR_COMMIT));
	buffer_append_cstr(&json, ",\n    \"lexborVersion\": ");
	append_json_string(&json, LXB_HTML_VERSION_STRING, strlen(LXB_HTML_VERSION_STRING));
	buffer_append_cstr(&json, ",\n    \"available\": true\n  }");

	if (ctx->status == ORACLE_OK) {
		if (ctx->tree.length == 0) {
			buffer_append_char(&ctx->tree, '\n');
		} else {
			if (ctx->tree.data[ctx->tree.length - 1] != '\n') {
				buffer_append_char(&ctx->tree, '\n');
			}
			buffer_append_char(&ctx->tree, '\n');
		}
		if (valid_utf8(ctx->tree.data == NULL ? "" : ctx->tree.data, ctx->tree.length)) {
			buffer_append_cstr(&json, ",\n  \"tree\": ");
			append_json_string(&json, ctx->tree.data == NULL ? "" : ctx->tree.data, ctx->tree.length);
		}
		buffer_append_cstr(&json, ",\n  \"treeBase64\": ");
		append_json_base64(&json, ctx->tree.data == NULL ? "" : ctx->tree.data, ctx->tree.length);
	}

	buffer_append_cstr(&json, ",\n  \"nodeCount\": ");
	{
		char count[32];
		snprintf(count, sizeof(count), "%zu", ctx->node_count);
		buffer_append_cstr(&json, count);
	}

	if (ctx->failure_class != NULL) {
		buffer_append_cstr(&json, ",\n  \"failureClass\": ");
		append_json_string(&json, ctx->failure_class, strlen(ctx->failure_class));
	}

	if (ctx->message != NULL) {
		const char *key = ctx->status == ORACLE_UNSUPPORTED ? "unsupported" : "error";
		buffer_append_cstr(&json, ",\n  \"");
		buffer_append_cstr(&json, key);
		if (ctx->status == ORACLE_UNSUPPORTED) {
			buffer_append_cstr(&json, "\": {\n    \"message\": ");
			append_json_string(&json, ctx->message, strlen(ctx->message));
			buffer_append_cstr(&json, "\n  }");
		} else {
			buffer_append_cstr(&json, "\": ");
			append_json_string(&json, ctx->message, strlen(ctx->message));
		}
	}

	buffer_append_cstr(&json, "\n}\n");

	if (json.failed) {
		fputs("{\"status\":\"error\",\"failureClass\":\"oracle-renderer-error\",\"error\":\"Could not encode JSON result.\"}\n", stdout);
	} else {
		fwrite(json.data, 1, json.length, stdout);
	}

	buffer_destroy(&json);
}

static void
print_cli_error(const char *message)
{
	render_ctx_t ctx;

	ctx.status = ORACLE_ERROR;
	ctx.failure_class = "oracle-cli-error";
	ctx.message = message;
	ctx.node_count = 0;
	ctx.max_nodes = 0;
	buffer_init(&ctx.tree);
	print_result(&ctx);
	buffer_destroy(&ctx.tree);
}

static bool
context_to_tag(const char *context, lxb_tag_id_t *tag_id, lxb_ns_id_t *ns_id)
{
	*ns_id = LXB_NS_HTML;

	if (strcmp(context, "body") == 0) {
		*tag_id = LXB_TAG_BODY;
	} else if (strcmp(context, "div") == 0) {
		*tag_id = LXB_TAG_DIV;
	} else if (strcmp(context, "p") == 0) {
		*tag_id = LXB_TAG_P;
	} else if (strcmp(context, "td") == 0) {
		*tag_id = LXB_TAG_TD;
	} else if (strcmp(context, "tr") == 0) {
		*tag_id = LXB_TAG_TR;
	} else if (strcmp(context, "table") == 0) {
		*tag_id = LXB_TAG_TABLE;
	} else if (strcmp(context, "caption") == 0) {
		*tag_id = LXB_TAG_CAPTION;
	} else if (strcmp(context, "colgroup") == 0) {
		*tag_id = LXB_TAG_COLGROUP;
	} else if (strcmp(context, "select") == 0) {
		*tag_id = LXB_TAG_SELECT;
	} else if (strcmp(context, "option") == 0) {
		*tag_id = LXB_TAG_OPTION;
	} else if (strcmp(context, "template") == 0) {
		*tag_id = LXB_TAG_TEMPLATE;
	} else if (strcmp(context, "title") == 0) {
		*tag_id = LXB_TAG_TITLE;
	} else if (strcmp(context, "textarea") == 0) {
		*tag_id = LXB_TAG_TEXTAREA;
	} else if (strcmp(context, "script") == 0) {
		*tag_id = LXB_TAG_SCRIPT;
	} else if (strcmp(context, "style") == 0) {
		*tag_id = LXB_TAG_STYLE;
	} else if (strcmp(context, "svg") == 0) {
		*tag_id = LXB_TAG_SVG;
		*ns_id = LXB_NS_SVG;
	} else if (strcmp(context, "math") == 0) {
		*tag_id = LXB_TAG_MATH;
		*ns_id = LXB_NS_MATH;
	} else {
		return false;
	}

	return true;
}

static void
render_full_document(render_ctx_t *ctx, const lxb_char_t *input, size_t input_len)
{
	lxb_status_t status;
	lxb_html_document_t *document = lxb_html_document_create();

	if (document == NULL) {
		ctx->status = ORACLE_ERROR;
		ctx->failure_class = "oracle-renderer-error";
		ctx->message = "Could not create Lexbor document.";
		return;
	}

	status = lxb_html_document_parse(document, input, input_len);
	if (status != LXB_STATUS_OK) {
		lxb_html_document_destroy(document);
		ctx->status = ORACLE_ERROR;
		ctx->failure_class = "oracle-parse-error";
		ctx->message = "Lexbor could not parse the input.";
		return;
	}

	render_children(ctx, lxb_dom_interface_node(document)->first_child, 0);
	lxb_html_document_destroy(document);
}

static void
render_fragment(render_ctx_t *ctx, const lxb_char_t *input, size_t input_len, const char *context)
{
	lxb_status_t status;
	lxb_html_parser_t *parser = NULL;
	lxb_html_document_t *document = NULL;
	lxb_dom_node_t *fragment = NULL;
	lxb_tag_id_t tag_id;
	lxb_ns_id_t ns_id;

	if (!context_to_tag(context, &tag_id, &ns_id)) {
		ctx->status = ORACLE_UNSUPPORTED;
		ctx->failure_class = "oracle-unsupported";
		ctx->message = "Unsupported fragment context.";
		return;
	}

	parser = lxb_html_parser_create();
	if (parser == NULL) {
		ctx->status = ORACLE_ERROR;
		ctx->failure_class = "oracle-renderer-error";
		ctx->message = "Could not create Lexbor parser.";
		return;
	}

	status = lxb_html_parser_init(parser);
	if (status != LXB_STATUS_OK) {
		lxb_html_parser_destroy(parser);
		ctx->status = ORACLE_ERROR;
		ctx->failure_class = "oracle-renderer-error";
		ctx->message = "Could not initialize Lexbor parser.";
		return;
	}

	document = lxb_html_document_create();
	if (document == NULL) {
		lxb_html_parser_destroy(parser);
		ctx->status = ORACLE_ERROR;
		ctx->failure_class = "oracle-renderer-error";
		ctx->message = "Could not create Lexbor document.";
		return;
	}

	fragment = lxb_html_parse_fragment_by_tag_id(parser, document, tag_id, ns_id, input, input_len);
	if (fragment == NULL || lxb_html_parser_status(parser) != LXB_STATUS_OK) {
		lxb_html_document_destroy(document);
		lxb_html_parser_destroy(parser);
		ctx->status = ORACLE_ERROR;
		ctx->failure_class = "oracle-parse-error";
		ctx->message = "Lexbor could not parse the fragment.";
		return;
	}

	render_children(ctx, fragment->first_child, 0);
	lxb_html_document_destroy(document);
	lxb_html_parser_destroy(parser);
}

int
main(int argc, char **argv)
{
	cli_options_t options;
	const char *message = NULL;
	lxb_char_t *input = NULL;
	size_t input_len = 0;
	render_ctx_t ctx;

	if (!parse_args(argc, argv, &options, &message)) {
		print_cli_error(message);
		return EXIT_FAILURE;
	}

	if (options.show_help) {
		print_usage(stdout);
		return EXIT_SUCCESS;
	}

	if (options.show_version) {
		print_version();
		return EXIT_SUCCESS;
	}

	buffer_init(&ctx.tree);
	ctx.status = ORACLE_OK;
	ctx.failure_class = NULL;
	ctx.message = NULL;
	ctx.node_count = 0;
	ctx.max_nodes = options.max_nodes;

	if (!read_file(options.input_path, &input, &input_len, &message)) {
		ctx.status = ORACLE_ERROR;
		ctx.failure_class = "oracle-cli-error";
		ctx.message = message;
		print_result(&ctx);
		buffer_destroy(&ctx.tree);
		return EXIT_FAILURE;
	}

	if (strcmp(options.mode, "full-document") == 0) {
		render_full_document(&ctx, input, input_len);
	} else {
		render_fragment(&ctx, input, input_len, options.context);
	}

	if (ctx.tree.failed && ctx.status == ORACLE_OK) {
		ctx.status = ORACLE_ERROR;
		ctx.failure_class = "oracle-renderer-error";
		ctx.message = "Could not allocate tree output.";
	}

	print_result(&ctx);
	free(input);
	buffer_destroy(&ctx.tree);

	return EXIT_SUCCESS;
}
