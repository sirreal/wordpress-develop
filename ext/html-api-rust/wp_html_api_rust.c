#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "Zend/zend_interfaces.h"
#include "ext/standard/info.h"
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

#include "php_wp_html_api_rust.h"

typedef struct _wp_html_api_rust_tag_scan {
	size_t tag_start;
	size_t tag_end;
	size_t name_start;
	size_t name_len;
	bool is_closing;
	bool has_self_closing_flag;
	size_t token_end;
	unsigned char token_type;
} wp_html_api_rust_tag_scan;

typedef struct _wp_html_api_rust_byte_slice {
	const unsigned char *ptr;
	size_t len;
} wp_html_api_rust_byte_slice;

typedef struct _wp_html_tag_processor_object {
	void *native;
	zend_long seek_count;
	zend_object std;
} wp_html_tag_processor_object;

typedef struct _wp_html_api_rust_text_replacement {
	size_t start;
	size_t length;
	zend_string *text;
} wp_html_api_rust_text_replacement;

static zend_class_entry *wp_html_tag_processor_ce;
static zend_class_entry *wp_html_processor_ce;
static zend_object_handlers wp_html_tag_processor_handlers;

extern const char *wp_html_api_rust_core_version(void);
extern bool wp_html_api_rust_scan_next_tag(
	const unsigned char *html,
	size_t html_len,
	size_t offset,
	wp_html_api_rust_tag_scan *out
);
extern void *wp_html_api_rust_tag_processor_new(const unsigned char *html, size_t html_len);
extern void wp_html_api_rust_tag_processor_free(void *processor);
extern bool wp_html_api_rust_tag_processor_next_tag(
	void *processor,
	const unsigned char *query,
	size_t query_len,
	bool visit_closers
);
extern bool wp_html_api_rust_tag_processor_next_token(void *processor);
extern void wp_html_api_rust_tag_processor_seek(void *processor, size_t offset);
extern void wp_html_api_rust_tag_processor_set_namespace(void *processor, unsigned char namespace_id);
extern bool wp_html_api_rust_tag_processor_apply_lexical_update(
	void *processor,
	size_t start,
	size_t length,
	const unsigned char *replacement,
	size_t replacement_len
);
extern bool wp_html_api_rust_tag_processor_current_span(
	const void *processor,
	size_t *start,
	size_t *length
);
extern unsigned char wp_html_api_rust_tag_processor_current_token_type(const void *processor);
extern bool wp_html_api_rust_tag_processor_paused_at_incomplete(const void *processor);
extern unsigned char wp_html_api_rust_tag_processor_subdivide_text_appropriately(void *processor);
extern bool wp_html_api_rust_tag_processor_get_modifiable_text(
	void *processor,
	wp_html_api_rust_byte_slice *out
);
extern bool wp_html_api_rust_tag_processor_set_modifiable_text(
	void *processor,
	const unsigned char *text,
	size_t text_len
);
extern unsigned char wp_html_api_rust_tag_processor_current_comment_type(const void *processor);
extern unsigned char wp_html_api_rust_tag_processor_script_content_type(const void *processor);
extern bool wp_html_api_rust_tag_processor_get_tag(
	const void *processor,
	wp_html_api_rust_byte_slice *out
);
extern bool wp_html_api_rust_tag_processor_is_tag_closer(const void *processor);
extern bool wp_html_api_rust_tag_processor_has_self_closing_flag(const void *processor);
extern unsigned char wp_html_api_rust_tag_processor_get_attribute(
	void *processor,
	const unsigned char *name,
	size_t name_len,
	wp_html_api_rust_byte_slice *out
);
extern unsigned char wp_html_api_rust_tag_processor_get_attribute_names_with_prefix(
	void *processor,
	const unsigned char *prefix,
	size_t prefix_len,
	wp_html_api_rust_byte_slice *out
);
extern bool wp_html_api_rust_tag_processor_set_attribute(
	void *processor,
	const unsigned char *name,
	size_t name_len,
	const unsigned char *value,
	size_t value_len,
	unsigned char value_kind
);
extern bool wp_html_api_rust_tag_processor_remove_attribute(
	void *processor,
	const unsigned char *name,
	size_t name_len
);
extern bool wp_html_api_rust_tag_processor_add_class(
	void *processor,
	const unsigned char *class_name,
	size_t class_name_len,
	bool quirks_mode
);
extern bool wp_html_api_rust_tag_processor_remove_class(
	void *processor,
	const unsigned char *class_name,
	size_t class_name_len,
	bool quirks_mode
);
extern unsigned char wp_html_api_rust_tag_processor_has_class(
	void *processor,
	const unsigned char *class_name,
	size_t class_name_len,
	bool quirks_mode
);
extern unsigned char wp_html_api_rust_tag_processor_class_list(
	void *processor,
	wp_html_api_rust_byte_slice *out,
	bool quirks_mode
);
extern bool wp_html_api_rust_tag_processor_get_html(
	const void *processor,
	wp_html_api_rust_byte_slice *out
);

PHP_INI_BEGIN()
	PHP_INI_ENTRY("wp_html_api_rust.replace_html_api", "0", PHP_INI_SYSTEM, NULL)
PHP_INI_END()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_api_rust_version, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_wp_html_api_rust_scan_next_tag, 0, 1, MAY_BE_ARRAY | MAY_BE_FALSE)
	ZEND_ARG_TYPE_INFO(0, html, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, offset, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wp_html_tag_processor_construct, 0, 0, 1)
	ZEND_ARG_INFO(0, html)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_tag_processor_next_tag, 0, 0, _IS_BOOL, 0)
	ZEND_ARG_INFO_WITH_DEFAULT_VALUE(0, query, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_tag_processor_get_tag, 0, 0, IS_STRING, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wp_html_tag_processor_get_attribute, 0, 0, 1)
	ZEND_ARG_INFO(0, name)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_tag_processor_get_attribute_names_with_prefix, 0, 1, IS_ARRAY, 1)
	ZEND_ARG_INFO(0, prefix)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_tag_processor_set_attribute, 0, 2, _IS_BOOL, 0)
	ZEND_ARG_INFO(0, name)
	ZEND_ARG_INFO(0, value)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_tag_processor_remove_attribute, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_INFO(0, name)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_tag_processor_class_mutation, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_INFO(0, class_name)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_tag_processor_has_class, 0, 1, _IS_BOOL, 1)
	ZEND_ARG_INFO(0, wanted_class)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wp_html_tag_processor_class_list, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_tag_processor_bool, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_tag_processor_nullable_string, 0, 0, IS_STRING, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_tag_processor_set_modifiable_text, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, plaintext_content, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wp_html_tag_processor_nullable_mixed, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_tag_processor_bookmark, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_INFO(0, bookmark_name)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_tag_processor_change_namespace, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, new_namespace, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wp_html_processor_construct, 0, 0, 1)
	ZEND_ARG_INFO(0, html)
	ZEND_ARG_INFO_WITH_DEFAULT_VALUE(0, use_the_static_create_methods_instead, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wp_html_processor_create_fragment, 0, 0, 1)
	ZEND_ARG_INFO(0, html)
	ZEND_ARG_INFO_WITH_DEFAULT_VALUE(0, context, "\"<body>\"")
	ZEND_ARG_INFO_WITH_DEFAULT_VALUE(0, encoding, "\"UTF-8\"")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wp_html_processor_create_full_parser, 0, 0, 1)
	ZEND_ARG_INFO(0, html)
	ZEND_ARG_INFO_WITH_DEFAULT_VALUE(0, encoding, "\"UTF-8\"")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_wp_html_tag_processor_get_html, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

static inline wp_html_tag_processor_object *wp_html_tag_processor_from_obj(zend_object *obj)
{
	return (wp_html_tag_processor_object *) ((char *) obj - XtOffsetOf(wp_html_tag_processor_object, std));
}

#define Z_WP_HTML_TAG_PROCESSOR_P(zv) wp_html_tag_processor_from_obj(Z_OBJ_P((zv)))

static zend_string *wp_html_api_rust_uppercase_ascii_slice(const unsigned char *ptr, size_t len)
{
	zend_string *string = zend_string_alloc(len, 0);
	size_t i;

	for (i = 0; i < len; i++) {
		unsigned char byte = ptr[i];
		ZSTR_VAL(string)[i] = (byte >= 'a' && byte <= 'z') ? (char) (byte - 32) : (char) byte;
	}

	ZSTR_VAL(string)[len] = '\0';
	return string;
}

static zend_string *wp_html_api_rust_lowercase_ascii_slice(const unsigned char *ptr, size_t len)
{
	zend_string *string = zend_string_alloc(len, 0);
	size_t i;

	for (i = 0; i < len; i++) {
		unsigned char byte = ptr[i];
		ZSTR_VAL(string)[i] = (byte >= 'A' && byte <= 'Z') ? (char) (byte + 32) : (char) byte;
	}

	ZSTR_VAL(string)[len] = '\0';
	return string;
}

static bool wp_html_api_rust_zend_string_equals_literal(zend_string *string, const char *literal, size_t literal_len)
{
	return ZSTR_LEN(string) == literal_len && 0 == memcmp(ZSTR_VAL(string), literal, literal_len);
}

static zend_string *wp_html_api_rust_svg_qualified_tag_name(zend_string *lower_tag_name)
{
#define WP_HTML_API_RUST_SVG_TAG(adjusted, canonical) \
	if (wp_html_api_rust_zend_string_equals_literal(lower_tag_name, adjusted, sizeof(adjusted) - 1)) { \
		return zend_string_init(canonical, sizeof(canonical) - 1, 0); \
	}

	WP_HTML_API_RUST_SVG_TAG("altglyph", "altGlyph")
	WP_HTML_API_RUST_SVG_TAG("altglyphdef", "altGlyphDef")
	WP_HTML_API_RUST_SVG_TAG("altglyphitem", "altGlyphItem")
	WP_HTML_API_RUST_SVG_TAG("animatecolor", "animateColor")
	WP_HTML_API_RUST_SVG_TAG("animatemotion", "animateMotion")
	WP_HTML_API_RUST_SVG_TAG("animatetransform", "animateTransform")
	WP_HTML_API_RUST_SVG_TAG("clippath", "clipPath")
	WP_HTML_API_RUST_SVG_TAG("feblend", "feBlend")
	WP_HTML_API_RUST_SVG_TAG("fecolormatrix", "feColorMatrix")
	WP_HTML_API_RUST_SVG_TAG("fecomponenttransfer", "feComponentTransfer")
	WP_HTML_API_RUST_SVG_TAG("fecomposite", "feComposite")
	WP_HTML_API_RUST_SVG_TAG("feconvolvematrix", "feConvolveMatrix")
	WP_HTML_API_RUST_SVG_TAG("fediffuselighting", "feDiffuseLighting")
	WP_HTML_API_RUST_SVG_TAG("fedisplacementmap", "feDisplacementMap")
	WP_HTML_API_RUST_SVG_TAG("fedistantlight", "feDistantLight")
	WP_HTML_API_RUST_SVG_TAG("fedropshadow", "feDropShadow")
	WP_HTML_API_RUST_SVG_TAG("feflood", "feFlood")
	WP_HTML_API_RUST_SVG_TAG("fefunca", "feFuncA")
	WP_HTML_API_RUST_SVG_TAG("fefuncb", "feFuncB")
	WP_HTML_API_RUST_SVG_TAG("fefuncg", "feFuncG")
	WP_HTML_API_RUST_SVG_TAG("fefuncr", "feFuncR")
	WP_HTML_API_RUST_SVG_TAG("fegaussianblur", "feGaussianBlur")
	WP_HTML_API_RUST_SVG_TAG("feimage", "feImage")
	WP_HTML_API_RUST_SVG_TAG("femerge", "feMerge")
	WP_HTML_API_RUST_SVG_TAG("femergenode", "feMergeNode")
	WP_HTML_API_RUST_SVG_TAG("femorphology", "feMorphology")
	WP_HTML_API_RUST_SVG_TAG("feoffset", "feOffset")
	WP_HTML_API_RUST_SVG_TAG("fepointlight", "fePointLight")
	WP_HTML_API_RUST_SVG_TAG("fespecularlighting", "feSpecularLighting")
	WP_HTML_API_RUST_SVG_TAG("fespotlight", "feSpotLight")
	WP_HTML_API_RUST_SVG_TAG("fetile", "feTile")
	WP_HTML_API_RUST_SVG_TAG("feturbulence", "feTurbulence")
	WP_HTML_API_RUST_SVG_TAG("foreignobject", "foreignObject")
	WP_HTML_API_RUST_SVG_TAG("glyphref", "glyphRef")
	WP_HTML_API_RUST_SVG_TAG("lineargradient", "linearGradient")
	WP_HTML_API_RUST_SVG_TAG("radialgradient", "radialGradient")
	WP_HTML_API_RUST_SVG_TAG("textpath", "textPath")

#undef WP_HTML_API_RUST_SVG_TAG

	return zend_string_copy(lower_tag_name);
}

static zend_string *wp_html_api_rust_svg_qualified_attribute_name(zend_string *lower_attribute_name)
{
#define WP_HTML_API_RUST_SVG_ATTRIBUTE(adjusted, canonical) \
	if (wp_html_api_rust_zend_string_equals_literal(lower_attribute_name, adjusted, sizeof(adjusted) - 1)) { \
		return zend_string_init(canonical, sizeof(canonical) - 1, 0); \
	}

	WP_HTML_API_RUST_SVG_ATTRIBUTE("attributename", "attributeName")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("attributetype", "attributeType")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("basefrequency", "baseFrequency")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("baseprofile", "baseProfile")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("calcmode", "calcMode")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("clippathunits", "clipPathUnits")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("diffuseconstant", "diffuseConstant")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("edgemode", "edgeMode")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("filterunits", "filterUnits")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("glyphref", "glyphRef")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("gradienttransform", "gradientTransform")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("gradientunits", "gradientUnits")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("kernelmatrix", "kernelMatrix")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("kernelunitlength", "kernelUnitLength")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("keypoints", "keyPoints")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("keysplines", "keySplines")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("keytimes", "keyTimes")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("lengthadjust", "lengthAdjust")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("limitingconeangle", "limitingConeAngle")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("markerheight", "markerHeight")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("markerunits", "markerUnits")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("markerwidth", "markerWidth")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("maskcontentunits", "maskContentUnits")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("maskunits", "maskUnits")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("numoctaves", "numOctaves")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("pathlength", "pathLength")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("patterncontentunits", "patternContentUnits")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("patterntransform", "patternTransform")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("patternunits", "patternUnits")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("pointsatx", "pointsAtX")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("pointsaty", "pointsAtY")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("pointsatz", "pointsAtZ")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("preservealpha", "preserveAlpha")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("preserveaspectratio", "preserveAspectRatio")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("primitiveunits", "primitiveUnits")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("refx", "refX")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("refy", "refY")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("repeatcount", "repeatCount")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("repeatdur", "repeatDur")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("requiredextensions", "requiredExtensions")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("requiredfeatures", "requiredFeatures")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("specularconstant", "specularConstant")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("specularexponent", "specularExponent")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("spreadmethod", "spreadMethod")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("startoffset", "startOffset")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("stddeviation", "stdDeviation")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("stitchtiles", "stitchTiles")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("surfacescale", "surfaceScale")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("systemlanguage", "systemLanguage")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("tablevalues", "tableValues")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("targetx", "targetX")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("targety", "targetY")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("textlength", "textLength")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("viewbox", "viewBox")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("viewtarget", "viewTarget")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("xchannelselector", "xChannelSelector")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("ychannelselector", "yChannelSelector")
	WP_HTML_API_RUST_SVG_ATTRIBUTE("zoomandpan", "zoomAndPan")

#undef WP_HTML_API_RUST_SVG_ATTRIBUTE

	return NULL;
}

static zend_string *wp_html_api_rust_foreign_qualified_attribute_name(zend_string *lower_attribute_name)
{
#define WP_HTML_API_RUST_FOREIGN_ATTRIBUTE(adjusted, canonical) \
	if (wp_html_api_rust_zend_string_equals_literal(lower_attribute_name, adjusted, sizeof(adjusted) - 1)) { \
		return zend_string_init(canonical, sizeof(canonical) - 1, 0); \
	}

	WP_HTML_API_RUST_FOREIGN_ATTRIBUTE("xlink:actuate", "xlink actuate")
	WP_HTML_API_RUST_FOREIGN_ATTRIBUTE("xlink:arcrole", "xlink arcrole")
	WP_HTML_API_RUST_FOREIGN_ATTRIBUTE("xlink:href", "xlink href")
	WP_HTML_API_RUST_FOREIGN_ATTRIBUTE("xlink:role", "xlink role")
	WP_HTML_API_RUST_FOREIGN_ATTRIBUTE("xlink:show", "xlink show")
	WP_HTML_API_RUST_FOREIGN_ATTRIBUTE("xlink:title", "xlink title")
	WP_HTML_API_RUST_FOREIGN_ATTRIBUTE("xlink:type", "xlink type")
	WP_HTML_API_RUST_FOREIGN_ATTRIBUTE("xml:lang", "xml lang")
	WP_HTML_API_RUST_FOREIGN_ATTRIBUTE("xml:space", "xml space")
	WP_HTML_API_RUST_FOREIGN_ATTRIBUTE("xmlns", "xmlns")
	WP_HTML_API_RUST_FOREIGN_ATTRIBUTE("xmlns:xlink", "xmlns xlink")

#undef WP_HTML_API_RUST_FOREIGN_ATTRIBUTE

	return NULL;
}

static bool wp_html_api_rust_ascii_eq_ci(const unsigned char *left, size_t left_len, const char *right)
{
	size_t i;
	size_t right_len = strlen(right);

	if (left_len != right_len) {
		return false;
	}

	for (i = 0; i < left_len; i++) {
		unsigned char left_byte = left[i];
		unsigned char right_byte = (unsigned char) right[i];

		if (left_byte >= 'a' && left_byte <= 'z') {
			left_byte = (unsigned char) (left_byte - 32);
		}

		if (right_byte >= 'a' && right_byte <= 'z') {
			right_byte = (unsigned char) (right_byte - 32);
		}

		if (left_byte != right_byte) {
			return false;
		}
	}

	return true;
}

static void wp_html_api_rust_doing_it_wrong(const char *function_name, const char *message, const char *version)
{
	zval callable;
	zval retval;
	zval params[3];
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	if (!zend_hash_str_exists(CG(function_table), "_doing_it_wrong", sizeof("_doing_it_wrong") - 1)) {
		return;
	}

	ZVAL_STRING(&callable, "_doing_it_wrong");
	ZVAL_STRING(&params[0], function_name);
	ZVAL_STRING(&params[1], message);
	ZVAL_STRING(&params[2], version);

	memset(&fci, 0, sizeof(fci));
	memset(&fcc, 0, sizeof(fcc));

	fci.size = sizeof(fci);
	fci.function_name = callable;
	fci.retval = &retval;
	fci.params = params;
	fci.param_count = 3;

	if (SUCCESS == zend_call_function(&fci, &fcc)) {
		zval_ptr_dtor(&retval);
	}

	zval_ptr_dtor(&params[2]);
	zval_ptr_dtor(&params[1]);
	zval_ptr_dtor(&params[0]);
	zval_ptr_dtor(&callable);
}

static bool wp_html_api_rust_is_valid_attribute_name(const char *name, size_t name_len)
{
	size_t i;
	uint32_t codepoint;
	unsigned char byte;

	if (0 == name_len) {
		return false;
	}

	for (i = 0; i < name_len; i++) {
		byte = (unsigned char) name[i];

		if (
			byte <= 0x1f ||
			'"' == byte ||
			'\'' == byte ||
			'>' == byte ||
			'&' == byte ||
			'<' == byte ||
			'/' == byte ||
			' ' == byte ||
			'=' == byte
		) {
			return false;
		}

		if (byte < 0x80) {
			continue;
		}

		codepoint = 0;
		if ((byte & 0xe0) == 0xc0 && i + 1 < name_len) {
			codepoint = ((uint32_t) (byte & 0x1f) << 6) |
				((uint32_t) ((unsigned char) name[i + 1] & 0x3f));
			i += 1;
		} else if ((byte & 0xf0) == 0xe0 && i + 2 < name_len) {
			codepoint = ((uint32_t) (byte & 0x0f) << 12) |
				((uint32_t) ((unsigned char) name[i + 1] & 0x3f) << 6) |
				((uint32_t) ((unsigned char) name[i + 2] & 0x3f));
			i += 2;
		} else if ((byte & 0xf8) == 0xf0 && i + 3 < name_len) {
			codepoint = ((uint32_t) (byte & 0x07) << 18) |
				((uint32_t) ((unsigned char) name[i + 1] & 0x3f) << 12) |
				((uint32_t) ((unsigned char) name[i + 2] & 0x3f) << 6) |
				((uint32_t) ((unsigned char) name[i + 3] & 0x3f));
			i += 3;
		}

		if (
			(codepoint >= 0xfdd0 && codepoint <= 0xfdef) ||
			(codepoint <= 0x10ffff && 0xfffe == (codepoint & 0xfffe))
		) {
			return false;
		}
	}

	return true;
}

static void wp_html_tag_processor_update_parser_state(zval *object, const char *state)
{
	zend_update_property_string(
		wp_html_tag_processor_ce,
		Z_OBJ_P(object),
		"parser_state",
		sizeof("parser_state") - 1,
		state
	);
}

static void wp_html_tag_processor_update_parser_state_from_native(zval *object, void *native)
{
	const char *state = "STATE_READY";

	switch (wp_html_api_rust_tag_processor_current_token_type(native)) {
		case 1:
			state = "STATE_MATCHED_TAG";
			break;
		case 2:
			state = "STATE_TEXT_NODE";
			break;
		case 3:
			state = "STATE_COMMENT";
			break;
		case 4:
			state = "STATE_DOCTYPE";
			break;
		case 5:
			state = "STATE_CDATA_NODE";
			break;
		case 6:
			state = "STATE_PRESUMPTUOUS_TAG";
			break;
		case 7:
			state = "STATE_FUNKY_COMMENT";
			break;
	}

	wp_html_tag_processor_update_parser_state(object, state);
}

static void wp_html_tag_processor_sync_html_property(zval *object, void *native)
{
	wp_html_api_rust_byte_slice html;

	if (NULL == native || !wp_html_api_rust_tag_processor_get_html(native, &html)) {
		zend_update_property_string(
			wp_html_tag_processor_ce,
			Z_OBJ_P(object),
			"html",
			sizeof("html") - 1,
			""
		);
		return;
	}

	zend_update_property_stringl(
		wp_html_tag_processor_ce,
		Z_OBJ_P(object),
		"html",
		sizeof("html") - 1,
		(const char *) html.ptr,
		html.len
	);
}

static zval *wp_html_tag_processor_read_bookmarks(zval *object, zval *rv)
{
	zval *bookmarks = zend_read_property(
		wp_html_tag_processor_ce,
		Z_OBJ_P(object),
		"bookmarks",
		sizeof("bookmarks") - 1,
		0,
		rv
	);

	if (IS_ARRAY != Z_TYPE_P(bookmarks)) {
		zval empty_bookmarks;

		array_init(&empty_bookmarks);
		zend_update_property(
			wp_html_tag_processor_ce,
			Z_OBJ_P(object),
			"bookmarks",
			sizeof("bookmarks") - 1,
			&empty_bookmarks
		);
		zval_ptr_dtor(&empty_bookmarks);

		bookmarks = zend_read_property(
			wp_html_tag_processor_ce,
			Z_OBJ_P(object),
			"bookmarks",
			sizeof("bookmarks") - 1,
			0,
			rv
		);
	}

	return bookmarks;
}

static bool wp_html_tag_processor_read_long_property(zval *object, const char *name, size_t name_len, zend_long *out)
{
	zval rv;
	zval *value;

	if (IS_OBJECT != Z_TYPE_P(object)) {
		return false;
	}

	value = zend_read_property(Z_OBJCE_P(object), Z_OBJ_P(object), name, name_len, 1, &rv);
	if (IS_LONG == Z_TYPE_P(value)) {
		*out = Z_LVAL_P(value);
		return true;
	}

	if (IS_DOUBLE == Z_TYPE_P(value)) {
		*out = (zend_long) Z_DVAL_P(value);
		return true;
	}

	return false;
}

static bool wp_html_tag_processor_read_string_property(zval *object, const char *name, size_t name_len, zend_string **out)
{
	zval rv;
	zval *value;

	if (IS_OBJECT != Z_TYPE_P(object)) {
		return false;
	}

	value = zend_read_property(Z_OBJCE_P(object), Z_OBJ_P(object), name, name_len, 1, &rv);
	if (IS_STRING != Z_TYPE_P(value)) {
		return false;
	}

	*out = Z_STR_P(value);
	return true;
}

static bool wp_html_api_rust_is_html_whitespace(unsigned char byte)
{
	return ' ' == byte || '\t' == byte || '\f' == byte || '\r' == byte || '\n' == byte;
}

static bool wp_html_tag_processor_parser_state_is(zval *object, const char *state, size_t state_len)
{
	zval rv;
	zval *parser_state;

	parser_state = zend_read_property(
		wp_html_tag_processor_ce,
		Z_OBJ_P(object),
		"parser_state",
		sizeof("parser_state") - 1,
		1,
		&rv
	);

	return (
		IS_STRING == Z_TYPE_P(parser_state) &&
		state_len == Z_STRLEN_P(parser_state) &&
		0 == memcmp(Z_STRVAL_P(parser_state), state, state_len)
	);
}

static bool wp_html_tag_processor_parser_state_is_terminal(zval *object)
{
	return (
		wp_html_tag_processor_parser_state_is(object, "STATE_COMPLETE", sizeof("STATE_COMPLETE") - 1) ||
		wp_html_tag_processor_parser_state_is(object, "STATE_INCOMPLETE_INPUT", sizeof("STATE_INCOMPLETE_INPUT") - 1)
	);
}

static bool wp_html_tag_processor_is_quirks_mode(zval *object)
{
	zval rv;
	zval *compat_mode;

	compat_mode = zend_read_property(
		wp_html_tag_processor_ce,
		Z_OBJ_P(object),
		"compat_mode",
		sizeof("compat_mode") - 1,
		1,
		&rv
	);

	return (
		IS_STRING == Z_TYPE_P(compat_mode) &&
		sizeof("quirks-mode") - 1 == Z_STRLEN_P(compat_mode) &&
		0 == memcmp(Z_STRVAL_P(compat_mode), "quirks-mode", sizeof("quirks-mode") - 1)
	);
}

static zend_long wp_html_tag_processor_max_bookmarks(zval *object)
{
	zend_string *processor_class_name;
	zend_class_entry *processor_ce;

	processor_class_name = zend_string_init("WP_HTML_Processor", sizeof("WP_HTML_Processor") - 1, 0);
	processor_ce = zend_lookup_class(processor_class_name);
	zend_string_release(processor_class_name);

	if (NULL != processor_ce && instanceof_function(Z_OBJCE_P(object), processor_ce)) {
		return 10000;
	}

	return 10;
}

static int wp_html_api_rust_compare_text_replacements(const void *left_ptr, const void *right_ptr)
{
	const wp_html_api_rust_text_replacement *left = (const wp_html_api_rust_text_replacement *) left_ptr;
	const wp_html_api_rust_text_replacement *right = (const wp_html_api_rust_text_replacement *) right_ptr;
	int by_text;

	if (left->start < right->start) {
		return -1;
	}

	if (left->start > right->start) {
		return 1;
	}

	by_text = zend_binary_strcmp(
		ZSTR_VAL(left->text),
		ZSTR_LEN(left->text),
		ZSTR_VAL(right->text),
		ZSTR_LEN(right->text)
	);
	if (0 != by_text) {
		return by_text;
	}

	if (left->length < right->length) {
		return -1;
	}

	if (left->length > right->length) {
		return 1;
	}

	return 0;
}

static bool wp_html_tag_processor_apply_lexical_updates(zval *object, void *native)
{
	zval rv;
	zval *updates;
	zval *update;
	zval empty_updates;
	wp_html_api_rust_text_replacement *replacements;
	uint32_t replacement_count = 0;
	uint32_t replacement_index = 0;
	zend_long accumulated_shift = 0;
	bool applied = true;

	if (NULL == native) {
		return false;
	}

	updates = zend_read_property(
		wp_html_tag_processor_ce,
		Z_OBJ_P(object),
		"lexical_updates",
		sizeof("lexical_updates") - 1,
		1,
		&rv
	);

	if (IS_ARRAY != Z_TYPE_P(updates) || 0 == zend_hash_num_elements(Z_ARRVAL_P(updates))) {
		return true;
	}

	replacements = safe_emalloc(zend_hash_num_elements(Z_ARRVAL_P(updates)), sizeof(wp_html_api_rust_text_replacement), 0);

	ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(updates), update) {
		zend_long start;
		zend_long length;
		zend_string *text;

		if (
			IS_OBJECT != Z_TYPE_P(update) ||
			!wp_html_tag_processor_read_long_property(update, "start", sizeof("start") - 1, &start) ||
			!wp_html_tag_processor_read_long_property(update, "length", sizeof("length") - 1, &length) ||
			!wp_html_tag_processor_read_string_property(update, "text", sizeof("text") - 1, &text) ||
			start < 0 ||
			length < 0
		) {
			applied = false;
			break;
		}

		replacements[replacement_count].start = (size_t) start;
		replacements[replacement_count].length = (size_t) length;
		replacements[replacement_count].text = zend_string_copy(text);
		++replacement_count;
	} ZEND_HASH_FOREACH_END();

	if (applied && replacement_count > 1) {
		qsort(
			replacements,
			replacement_count,
			sizeof(wp_html_api_rust_text_replacement),
			wp_html_api_rust_compare_text_replacements
		);
	}

	for (replacement_index = 0; applied && replacement_index < replacement_count; ++replacement_index) {
		zend_long adjusted_start = (zend_long) replacements[replacement_index].start + accumulated_shift;
		zend_long shift = (zend_long) ZSTR_LEN(replacements[replacement_index].text) - (zend_long) replacements[replacement_index].length;

		if (
			adjusted_start < 0 ||
			!wp_html_api_rust_tag_processor_apply_lexical_update(
				native,
				(size_t) adjusted_start,
				replacements[replacement_index].length,
				(const unsigned char *) ZSTR_VAL(replacements[replacement_index].text),
				ZSTR_LEN(replacements[replacement_index].text)
			)
		) {
			applied = false;
			break;
		}

		accumulated_shift += shift;
	}

	for (replacement_index = 0; replacement_index < replacement_count; ++replacement_index) {
		zend_string_release(replacements[replacement_index].text);
	}
	efree(replacements);

	if (!applied) {
		return false;
	}

	array_init(&empty_updates);
	zend_update_property(
		wp_html_tag_processor_ce,
		Z_OBJ_P(object),
		"lexical_updates",
		sizeof("lexical_updates") - 1,
		&empty_updates
	);
	zval_ptr_dtor(&empty_updates);

	wp_html_tag_processor_sync_html_property(object, native);
	return true;
}

static void wp_html_tag_processor_create_span(zval *span, zend_long start, zend_long length)
{
	zend_string *span_class_name;
	zend_class_entry *span_ce;

	span_class_name = zend_string_init("WP_HTML_Span", sizeof("WP_HTML_Span") - 1, 0);
	span_ce = zend_lookup_class(span_class_name);
	zend_string_release(span_class_name);

	if (NULL != span_ce) {
		object_init_ex(span, span_ce);
		zend_update_property_long(span_ce, Z_OBJ_P(span), "start", sizeof("start") - 1, start);
		zend_update_property_long(span_ce, Z_OBJ_P(span), "length", sizeof("length") - 1, length);
		return;
	}

	object_init(span);
	add_property_long(span, "start", start);
	add_property_long(span, "length", length);
}

static void wp_html_tag_processor_adjust_bookmarks_after_current_token_update(
	zval *object,
	zend_long old_start,
	zend_long old_length,
	zend_long new_start,
	zend_long new_length
) {
	zval rv;
	zval *bookmarks;
	zval *bookmark;
	zend_long delta = new_length - old_length;

	if (old_start != new_start && 0 == delta) {
		delta = new_start - old_start;
	}

	if (0 == delta && old_length == new_length) {
		return;
	}

	bookmarks = wp_html_tag_processor_read_bookmarks(object, &rv);
	ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(bookmarks), bookmark) {
		zend_long start;
		zend_long length;

		if (
			IS_OBJECT != Z_TYPE_P(bookmark) ||
			!wp_html_tag_processor_read_long_property(bookmark, "start", sizeof("start") - 1, &start) ||
			!wp_html_tag_processor_read_long_property(bookmark, "length", sizeof("length") - 1, &length)
		) {
			continue;
		}

		if (start == old_start) {
			zend_update_property_long(
				Z_OBJCE_P(bookmark),
				Z_OBJ_P(bookmark),
				"length",
				sizeof("length") - 1,
				new_length
			);
		} else if (start > old_start) {
			zend_update_property_long(
				Z_OBJCE_P(bookmark),
				Z_OBJ_P(bookmark),
				"start",
				sizeof("start") - 1,
				start + delta
			);
		}
	} ZEND_HASH_FOREACH_END();
}

static bool wp_html_tag_processor_initialize(zval *object, const char *html, size_t html_len)
{
	wp_html_tag_processor_object *intern = Z_WP_HTML_TAG_PROCESSOR_P(object);
	zval bookmarks;
	zval lexical_updates;

	if (NULL != intern->native) {
		wp_html_api_rust_tag_processor_free(intern->native);
	}

	intern->native = wp_html_api_rust_tag_processor_new((const unsigned char *) html, html_len);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "Failed to initialize WP_HTML_Tag_Processor native state");
		return false;
	}

	intern->seek_count = 0;
	wp_html_tag_processor_sync_html_property(object, intern->native);
	wp_html_tag_processor_update_parser_state(object, "STATE_READY");
	zend_update_property_string(
		wp_html_tag_processor_ce,
		Z_OBJ_P(object),
		"parsing_namespace",
		sizeof("parsing_namespace") - 1,
		"html"
	);

	array_init(&bookmarks);
	zend_update_property(
		wp_html_tag_processor_ce,
		Z_OBJ_P(object),
		"bookmarks",
		sizeof("bookmarks") - 1,
		&bookmarks
	);
	zval_ptr_dtor(&bookmarks);

	array_init(&lexical_updates);
	zend_update_property(
		wp_html_tag_processor_ce,
		Z_OBJ_P(object),
		"lexical_updates",
		sizeof("lexical_updates") - 1,
		&lexical_updates
	);
	zval_ptr_dtor(&lexical_updates);

	return true;
}

static zend_object *wp_html_tag_processor_create_object(zend_class_entry *class_type)
{
	wp_html_tag_processor_object *intern = zend_object_alloc(sizeof(wp_html_tag_processor_object), class_type);

	zend_object_std_init(&intern->std, class_type);
	object_properties_init(&intern->std, class_type);

	intern->native = NULL;
	intern->seek_count = 0;
	intern->std.handlers = &wp_html_tag_processor_handlers;

	return &intern->std;
}

static void wp_html_tag_processor_free_obj(zend_object *object)
{
	wp_html_tag_processor_object *intern = wp_html_tag_processor_from_obj(object);

	if (NULL != intern->native) {
		wp_html_api_rust_tag_processor_free(intern->native);
		intern->native = NULL;
	}

	zend_object_std_dtor(&intern->std);
}

PHP_FUNCTION(wp_html_api_rust_version)
{
	ZEND_PARSE_PARAMETERS_NONE();

	RETURN_STRING(wp_html_api_rust_core_version());
}

PHP_FUNCTION(wp_html_api_rust_scan_next_tag)
{
	char *html;
	size_t html_len;
	zend_long offset = 0;
	wp_html_api_rust_tag_scan scan;
	zend_string *tag_name;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_STRING(html, html_len)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(offset)
	ZEND_PARSE_PARAMETERS_END();

	if (offset < 0) {
		offset = 0;
	}

	if (!wp_html_api_rust_scan_next_tag((const unsigned char *) html, html_len, (size_t) offset, &scan)) {
		RETURN_FALSE;
	}

	tag_name = wp_html_api_rust_uppercase_ascii_slice((const unsigned char *) html + scan.name_start, scan.name_len);

	array_init(return_value);
	add_assoc_str(return_value, "tag_name", tag_name);
	add_assoc_long(return_value, "tag_start", (zend_long) scan.tag_start);
	add_assoc_long(return_value, "tag_end", (zend_long) scan.tag_end);
	add_assoc_long(return_value, "name_start", (zend_long) scan.name_start);
	add_assoc_long(return_value, "name_length", (zend_long) scan.name_len);
	add_assoc_bool(return_value, "is_closing", scan.is_closing);
}

PHP_METHOD(WP_HTML_Tag_Processor, __construct)
{
	zval *html_param;
	const char *html = "";
	size_t html_len = 0;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(html_param)
	ZEND_PARSE_PARAMETERS_END();

	if (IS_STRING == Z_TYPE_P(html_param)) {
		html = Z_STRVAL_P(html_param);
		html_len = Z_STRLEN_P(html_param);
	} else {
		wp_html_api_rust_doing_it_wrong(
			"WP_HTML_Tag_Processor::__construct",
			"The HTML parameter must be a string.",
			"6.9.0"
		);
	}

	if (!wp_html_tag_processor_initialize(ZEND_THIS, html, html_len)) {
		RETURN_THROWS();
	}
}

PHP_METHOD(WP_HTML_Tag_Processor, next_tag)
{
	zval *query = NULL;
	zend_string *query_tag_name = NULL;
	zend_string *query_class_name = NULL;
	zend_long match_offset = 1;
	zend_long found_matches = 0;
	bool visit_closers = false;
	wp_html_tag_processor_object *intern;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_ZVAL(query)
	ZEND_PARSE_PARAMETERS_END();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (!wp_html_tag_processor_apply_lexical_updates(ZEND_THIS, intern->native)) {
		RETURN_FALSE;
	}

	if (NULL != query) {
		if (IS_STRING == Z_TYPE_P(query)) {
			query_tag_name = Z_STR_P(query);
		} else if (IS_ARRAY == Z_TYPE_P(query)) {
			zval *tag_name = zend_hash_str_find(Z_ARRVAL_P(query), "tag_name", sizeof("tag_name") - 1);
			zval *class_name = zend_hash_str_find(Z_ARRVAL_P(query), "class_name", sizeof("class_name") - 1);
			zval *query_match_offset = zend_hash_str_find(Z_ARRVAL_P(query), "match_offset", sizeof("match_offset") - 1);
			zval *tag_closers = zend_hash_str_find(Z_ARRVAL_P(query), "tag_closers", sizeof("tag_closers") - 1);

			if (NULL != tag_name && IS_STRING == Z_TYPE_P(tag_name)) {
				query_tag_name = Z_STR_P(tag_name);
			}

			if (NULL != class_name && IS_STRING == Z_TYPE_P(class_name)) {
				query_class_name = Z_STR_P(class_name);
			}

			if (NULL != query_match_offset && IS_LONG == Z_TYPE_P(query_match_offset) && Z_LVAL_P(query_match_offset) > 0) {
				match_offset = Z_LVAL_P(query_match_offset);
			}

			if (
				NULL != tag_closers &&
				IS_STRING == Z_TYPE_P(tag_closers) &&
				sizeof("visit") - 1 == Z_STRLEN_P(tag_closers) &&
				0 == memcmp(Z_STRVAL_P(tag_closers), "visit", sizeof("visit") - 1)
			) {
				visit_closers = true;
			}
		}
	}

	while (wp_html_api_rust_tag_processor_next_tag(
		intern->native,
		NULL == query_tag_name ? NULL : (const unsigned char *) ZSTR_VAL(query_tag_name),
		NULL == query_tag_name ? 0 : ZSTR_LEN(query_tag_name),
		visit_closers
	)) {
		if (
			NULL != query_class_name &&
			2 != wp_html_api_rust_tag_processor_has_class(
				intern->native,
				(const unsigned char *) ZSTR_VAL(query_class_name),
				ZSTR_LEN(query_class_name),
				wp_html_tag_processor_is_quirks_mode(ZEND_THIS)
			)
		) {
			continue;
		}

		if (++found_matches < match_offset) {
			continue;
		}

		wp_html_tag_processor_update_parser_state_from_native(ZEND_THIS, intern->native);
		RETURN_TRUE;
	}

	wp_html_tag_processor_update_parser_state(
		ZEND_THIS,
		wp_html_api_rust_tag_processor_paused_at_incomplete(intern->native) ? "STATE_INCOMPLETE_INPUT" : "STATE_COMPLETE"
	);
	RETURN_FALSE;
}

PHP_METHOD(WP_HTML_Tag_Processor, next_token)
{
	wp_html_tag_processor_object *intern;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (!wp_html_tag_processor_apply_lexical_updates(ZEND_THIS, intern->native)) {
		RETURN_FALSE;
	}

	if (wp_html_api_rust_tag_processor_next_token(intern->native)) {
		wp_html_tag_processor_update_parser_state_from_native(ZEND_THIS, intern->native);
		RETURN_TRUE;
	}

	wp_html_tag_processor_update_parser_state(
		ZEND_THIS,
		wp_html_api_rust_tag_processor_paused_at_incomplete(intern->native) ? "STATE_INCOMPLETE_INPUT" : "STATE_COMPLETE"
	);
	RETURN_FALSE;
}

PHP_METHOD(WP_HTML_Tag_Processor, get_tag)
{
	wp_html_tag_processor_object *intern;
	wp_html_api_rust_byte_slice tag_name;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1)) {
		if (
			!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_COMMENT", sizeof("STATE_COMMENT") - 1) ||
			4 != wp_html_api_rust_tag_processor_current_comment_type(intern->native)
		) {
			RETURN_NULL();
		}
	}

	if (!wp_html_api_rust_tag_processor_get_tag(intern->native, &tag_name)) {
		RETURN_NULL();
	}

	if (4 == wp_html_api_rust_tag_processor_current_comment_type(intern->native)) {
		RETURN_STRINGL((const char *) tag_name.ptr, tag_name.len);
	}

	RETURN_STR(wp_html_api_rust_uppercase_ascii_slice(tag_name.ptr, tag_name.len));
}

PHP_METHOD(WP_HTML_Tag_Processor, get_attribute)
{
	char *name;
	size_t name_len;
	wp_html_tag_processor_object *intern;
	wp_html_api_rust_byte_slice value;
	unsigned char result;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STRING(name, name_len)
	ZEND_PARSE_PARAMETERS_END();

	if (!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1)) {
		RETURN_NULL();
	}

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	result = wp_html_api_rust_tag_processor_get_attribute(
		intern->native,
		(const unsigned char *) name,
		name_len,
		&value
	);

	switch (result) {
		case 1:
			RETURN_TRUE;
		case 2:
			RETURN_STRINGL((const char *) value.ptr, value.len);
		default:
			RETURN_NULL();
	}
}

PHP_METHOD(WP_HTML_Tag_Processor, get_attribute_names_with_prefix)
{
	char *prefix;
	size_t prefix_len;
	wp_html_tag_processor_object *intern;
	wp_html_api_rust_byte_slice names;
	size_t start = 0;
	size_t i;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STRING(prefix, prefix_len)
	ZEND_PARSE_PARAMETERS_END();

	if (!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1)) {
		RETURN_NULL();
	}

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (!wp_html_api_rust_tag_processor_get_attribute_names_with_prefix(
		intern->native,
		(const unsigned char *) prefix,
		prefix_len,
		&names
	)) {
		RETURN_NULL();
	}

	array_init(return_value);
	if (0 == names.len) {
		return;
	}

	for (i = 0; i <= names.len; i++) {
		if (i == names.len || 0 == names.ptr[i]) {
			add_next_index_stringl(return_value, (const char *) names.ptr + start, i - start);
			start = i + 1;
		}
	}
}

PHP_METHOD(WP_HTML_Tag_Processor, set_attribute)
{
	char *name;
	size_t name_len;
	zval *value;
	zend_string *value_string = NULL;
	const unsigned char *value_ptr = NULL;
	size_t value_len = 0;
	unsigned char value_kind = 2;
	bool result;
	bool had_span;
	size_t old_token_start = 0;
	size_t old_token_length = 0;
	size_t new_token_start = 0;
	size_t new_token_length = 0;
	wp_html_tag_processor_object *intern;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_STRING(name, name_len)
		Z_PARAM_ZVAL(value)
	ZEND_PARSE_PARAMETERS_END();

	if (!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1)) {
		RETURN_FALSE;
	}

	if (!wp_html_api_rust_is_valid_attribute_name(name, name_len)) {
		wp_html_api_rust_doing_it_wrong(
			"WP_HTML_Tag_Processor::set_attribute",
			"Invalid attribute name.",
			"6.2.0"
		);
		RETURN_FALSE;
	}

	if (IS_FALSE == Z_TYPE_P(value)) {
		value_kind = 0;
	} else if (IS_TRUE == Z_TYPE_P(value)) {
		value_kind = 1;
	} else {
		value_string = zval_get_string(value);
		value_ptr = (const unsigned char *) ZSTR_VAL(value_string);
		value_len = ZSTR_LEN(value_string);
	}

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		if (NULL != value_string) {
			zend_string_release(value_string);
		}
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	had_span = wp_html_api_rust_tag_processor_current_span(intern->native, &old_token_start, &old_token_length);
	result = wp_html_api_rust_tag_processor_set_attribute(
		intern->native,
		(const unsigned char *) name,
		name_len,
		value_ptr,
		value_len,
		value_kind
	);

	if (NULL != value_string) {
		zend_string_release(value_string);
	}

	if (result) {
		if (
			had_span &&
			wp_html_api_rust_tag_processor_current_span(intern->native, &new_token_start, &new_token_length)
		) {
			wp_html_tag_processor_adjust_bookmarks_after_current_token_update(
				ZEND_THIS,
				(zend_long) old_token_start,
				(zend_long) old_token_length,
				(zend_long) new_token_start,
				(zend_long) new_token_length
			);
		}
		wp_html_tag_processor_sync_html_property(ZEND_THIS, intern->native);
	}

	RETURN_BOOL(result);
}

PHP_METHOD(WP_HTML_Tag_Processor, remove_attribute)
{
	char *name;
	size_t name_len;
	wp_html_tag_processor_object *intern;
	bool had_span;
	size_t old_token_start = 0;
	size_t old_token_length = 0;
	size_t new_token_start = 0;
	size_t new_token_length = 0;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STRING(name, name_len)
	ZEND_PARSE_PARAMETERS_END();

	if (!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1)) {
		RETURN_FALSE;
	}

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	{
		had_span = wp_html_api_rust_tag_processor_current_span(intern->native, &old_token_start, &old_token_length);
		bool result = wp_html_api_rust_tag_processor_remove_attribute(
			intern->native,
			(const unsigned char *) name,
			name_len
		);

		if (result) {
			if (
				had_span &&
				wp_html_api_rust_tag_processor_current_span(intern->native, &new_token_start, &new_token_length)
			) {
				wp_html_tag_processor_adjust_bookmarks_after_current_token_update(
					ZEND_THIS,
					(zend_long) old_token_start,
					(zend_long) old_token_length,
					(zend_long) new_token_start,
					(zend_long) new_token_length
				);
			}
			wp_html_tag_processor_sync_html_property(ZEND_THIS, intern->native);
		}

		RETURN_BOOL(result);
	}
}

PHP_METHOD(WP_HTML_Tag_Processor, add_class)
{
	char *class_name;
	size_t class_name_len;
	wp_html_tag_processor_object *intern;
	bool had_span;
	size_t old_token_start = 0;
	size_t old_token_length = 0;
	size_t new_token_start = 0;
	size_t new_token_length = 0;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STRING(class_name, class_name_len)
	ZEND_PARSE_PARAMETERS_END();

	if (!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1)) {
		RETURN_FALSE;
	}

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	{
		had_span = wp_html_api_rust_tag_processor_current_span(intern->native, &old_token_start, &old_token_length);
		bool result = wp_html_api_rust_tag_processor_add_class(
			intern->native,
			(const unsigned char *) class_name,
			class_name_len,
			wp_html_tag_processor_is_quirks_mode(ZEND_THIS)
		);

		if (result) {
			if (
				had_span &&
				wp_html_api_rust_tag_processor_current_span(intern->native, &new_token_start, &new_token_length)
			) {
				wp_html_tag_processor_adjust_bookmarks_after_current_token_update(
					ZEND_THIS,
					(zend_long) old_token_start,
					(zend_long) old_token_length,
					(zend_long) new_token_start,
					(zend_long) new_token_length
				);
			}
			wp_html_tag_processor_sync_html_property(ZEND_THIS, intern->native);
		}

		RETURN_BOOL(result);
	}
}

PHP_METHOD(WP_HTML_Tag_Processor, remove_class)
{
	char *class_name;
	size_t class_name_len;
	wp_html_tag_processor_object *intern;
	bool had_span;
	size_t old_token_start = 0;
	size_t old_token_length = 0;
	size_t new_token_start = 0;
	size_t new_token_length = 0;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STRING(class_name, class_name_len)
	ZEND_PARSE_PARAMETERS_END();

	if (!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1)) {
		RETURN_FALSE;
	}

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	{
		had_span = wp_html_api_rust_tag_processor_current_span(intern->native, &old_token_start, &old_token_length);
		bool result = wp_html_api_rust_tag_processor_remove_class(
			intern->native,
			(const unsigned char *) class_name,
			class_name_len,
			wp_html_tag_processor_is_quirks_mode(ZEND_THIS)
		);

		if (result) {
			if (
				had_span &&
				wp_html_api_rust_tag_processor_current_span(intern->native, &new_token_start, &new_token_length)
			) {
				wp_html_tag_processor_adjust_bookmarks_after_current_token_update(
					ZEND_THIS,
					(zend_long) old_token_start,
					(zend_long) old_token_length,
					(zend_long) new_token_start,
					(zend_long) new_token_length
				);
			}
			wp_html_tag_processor_sync_html_property(ZEND_THIS, intern->native);
		}

		RETURN_BOOL(result);
	}
}

PHP_METHOD(WP_HTML_Tag_Processor, has_class)
{
	char *class_name;
	size_t class_name_len;
	wp_html_tag_processor_object *intern;
	unsigned char result;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STRING(class_name, class_name_len)
	ZEND_PARSE_PARAMETERS_END();

	if (!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1)) {
		RETURN_NULL();
	}

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	result = wp_html_api_rust_tag_processor_has_class(
		intern->native,
		(const unsigned char *) class_name,
		class_name_len,
		wp_html_tag_processor_is_quirks_mode(ZEND_THIS)
	);

	if (0 == result) {
		RETURN_NULL();
	}

	RETURN_BOOL(2 == result);
}

PHP_METHOD(WP_HTML_Tag_Processor, class_list)
{
	wp_html_tag_processor_object *intern;
	wp_html_api_rust_byte_slice classes;
	size_t start = 0;
	size_t i;

	ZEND_PARSE_PARAMETERS_NONE();

	if (!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1)) {
		RETURN_NULL();
	}

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (!wp_html_api_rust_tag_processor_class_list(
		intern->native,
		&classes,
		wp_html_tag_processor_is_quirks_mode(ZEND_THIS)
	)) {
		RETURN_NULL();
	}

	array_init(return_value);
	if (0 == classes.len) {
		return;
	}

	for (i = 0; i <= classes.len; i++) {
		if (i == classes.len || 0x1f == classes.ptr[i]) {
			add_next_index_stringl(return_value, (const char *) classes.ptr + start, i - start);
			start = i + 1;
		}
	}
}

PHP_METHOD(WP_HTML_Tag_Processor, is_tag_closer)
{
	wp_html_tag_processor_object *intern;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1)) {
		RETURN_FALSE;
	}

	RETURN_BOOL(wp_html_api_rust_tag_processor_is_tag_closer(intern->native));
}

PHP_METHOD(WP_HTML_Tag_Processor, has_self_closing_flag)
{
	wp_html_tag_processor_object *intern;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1)) {
		RETURN_FALSE;
	}

	RETURN_BOOL(wp_html_api_rust_tag_processor_has_self_closing_flag(intern->native));
}

PHP_METHOD(WP_HTML_Tag_Processor, get_token_name)
{
	wp_html_tag_processor_object *intern;
	wp_html_api_rust_byte_slice tag_name;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_TEXT_NODE", sizeof("STATE_TEXT_NODE") - 1)) {
		RETURN_STRING("#text");
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_COMMENT", sizeof("STATE_COMMENT") - 1)) {
		RETURN_STRING("#comment");
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_DOCTYPE", sizeof("STATE_DOCTYPE") - 1)) {
		RETURN_STRING("html");
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_CDATA_NODE", sizeof("STATE_CDATA_NODE") - 1)) {
		RETURN_STRING("#cdata-section");
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_PRESUMPTUOUS_TAG", sizeof("STATE_PRESUMPTUOUS_TAG") - 1)) {
		RETURN_STRING("#presumptuous-tag");
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_FUNKY_COMMENT", sizeof("STATE_FUNKY_COMMENT") - 1)) {
		RETURN_STRING("#funky-comment");
	}

	if (!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1)) {
		RETURN_NULL();
	}

	if (!wp_html_api_rust_tag_processor_get_tag(intern->native, &tag_name)) {
		RETURN_NULL();
	}

	RETURN_STR(wp_html_api_rust_uppercase_ascii_slice(tag_name.ptr, tag_name.len));
}

PHP_METHOD(WP_HTML_Tag_Processor, get_token_type)
{
	wp_html_tag_processor_object *intern;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1)) {
		RETURN_STRING("#tag");
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_DOCTYPE", sizeof("STATE_DOCTYPE") - 1)) {
		RETURN_STRING("#doctype");
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_TEXT_NODE", sizeof("STATE_TEXT_NODE") - 1)) {
		RETURN_STRING("#text");
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_COMMENT", sizeof("STATE_COMMENT") - 1)) {
		RETURN_STRING("#comment");
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_CDATA_NODE", sizeof("STATE_CDATA_NODE") - 1)) {
		RETURN_STRING("#cdata-section");
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_PRESUMPTUOUS_TAG", sizeof("STATE_PRESUMPTUOUS_TAG") - 1)) {
		RETURN_STRING("#presumptuous-tag");
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_FUNKY_COMMENT", sizeof("STATE_FUNKY_COMMENT") - 1)) {
		RETURN_STRING("#funky-comment");
	}

	RETURN_NULL();
}

PHP_METHOD(WP_HTML_Tag_Processor, paused_at_incomplete_token)
{
	wp_html_tag_processor_object *intern;
	zval rv;
	zval *state;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL != intern->native && wp_html_api_rust_tag_processor_paused_at_incomplete(intern->native)) {
		RETURN_TRUE;
	}

	state = zend_read_property(
		wp_html_tag_processor_ce,
		Z_OBJ_P(ZEND_THIS),
		"parser_state",
		sizeof("parser_state") - 1,
		1,
		&rv
	);

	RETURN_BOOL(
		IS_STRING == Z_TYPE_P(state) &&
		sizeof("STATE_INCOMPLETE_INPUT") - 1 == Z_STRLEN_P(state) &&
		0 == memcmp(Z_STRVAL_P(state), "STATE_INCOMPLETE_INPUT", sizeof("STATE_INCOMPLETE_INPUT") - 1)
	);
}

PHP_METHOD(WP_HTML_Tag_Processor, subdivide_text_appropriately)
{
	wp_html_tag_processor_object *intern;
	zval rv;
	zval *parser_state;
	unsigned char classification;

	ZEND_PARSE_PARAMETERS_NONE();

	parser_state = zend_read_property(
		wp_html_tag_processor_ce,
		Z_OBJ_P(ZEND_THIS),
		"parser_state",
		sizeof("parser_state") - 1,
		1,
		&rv
	);
	if (
		IS_STRING != Z_TYPE_P(parser_state) ||
		sizeof("STATE_TEXT_NODE") - 1 != Z_STRLEN_P(parser_state) ||
		0 != memcmp(Z_STRVAL_P(parser_state), "STATE_TEXT_NODE", sizeof("STATE_TEXT_NODE") - 1)
	) {
		RETURN_FALSE;
	}

	zend_update_property_string(
		wp_html_tag_processor_ce,
		Z_OBJ_P(ZEND_THIS),
		"text_node_classification",
		sizeof("text_node_classification") - 1,
		"TEXT_IS_GENERIC"
	);

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	classification = wp_html_api_rust_tag_processor_subdivide_text_appropriately(intern->native);
	if (1 == classification) {
		zend_update_property_string(
			wp_html_tag_processor_ce,
			Z_OBJ_P(ZEND_THIS),
			"text_node_classification",
			sizeof("text_node_classification") - 1,
			"TEXT_IS_NULL_SEQUENCE"
		);
		RETURN_TRUE;
	}

	if (2 == classification) {
		zend_update_property_string(
			wp_html_tag_processor_ce,
			Z_OBJ_P(ZEND_THIS),
			"text_node_classification",
			sizeof("text_node_classification") - 1,
			"TEXT_IS_WHITESPACE"
		);
		RETURN_TRUE;
	}

	RETURN_FALSE;
}

PHP_METHOD(WP_HTML_Tag_Processor, get_modifiable_text)
{
	wp_html_tag_processor_object *intern;
	wp_html_api_rust_byte_slice text;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (!wp_html_api_rust_tag_processor_get_modifiable_text(intern->native, &text)) {
		RETURN_EMPTY_STRING();
	}

	RETURN_STRINGL((const char *) text.ptr, text.len);
}

PHP_METHOD(WP_HTML_Tag_Processor, native_get_script_content_type)
{
	wp_html_tag_processor_object *intern;
	unsigned char content_type;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	content_type = wp_html_api_rust_tag_processor_script_content_type(intern->native);
	switch (content_type) {
		case 1:
			RETURN_STRING("javascript");
		case 2:
			RETURN_STRING("json");
		default:
			RETURN_NULL();
	}
}

PHP_METHOD(WP_HTML_Tag_Processor, set_modifiable_text)
{
	char *text;
	size_t text_len;
	wp_html_tag_processor_object *intern;
	zval rv;
	zval *parser_state;
	bool had_span;
	size_t old_token_start = 0;
	size_t old_token_length = 0;
	size_t new_token_start = 0;
	size_t new_token_length = 0;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STRING(text, text_len)
	ZEND_PARSE_PARAMETERS_END();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	parser_state = zend_read_property(
		wp_html_tag_processor_ce,
		Z_OBJ_P(ZEND_THIS),
		"parser_state",
		sizeof("parser_state") - 1,
		1,
		&rv
	);
	if (
		IS_STRING == Z_TYPE_P(parser_state) &&
		(
			(
				sizeof("STATE_COMPLETE") - 1 == Z_STRLEN_P(parser_state) &&
				0 == memcmp(Z_STRVAL_P(parser_state), "STATE_COMPLETE", sizeof("STATE_COMPLETE") - 1)
			) ||
			(
				sizeof("STATE_INCOMPLETE_INPUT") - 1 == Z_STRLEN_P(parser_state) &&
				0 == memcmp(Z_STRVAL_P(parser_state), "STATE_INCOMPLETE_INPUT", sizeof("STATE_INCOMPLETE_INPUT") - 1)
			)
		)
	) {
		RETURN_FALSE;
	}

	had_span = wp_html_api_rust_tag_processor_current_span(intern->native, &old_token_start, &old_token_length);
	if (
		wp_html_api_rust_tag_processor_set_modifiable_text(
			intern->native,
			(const unsigned char *) text,
			text_len
		)
	) {
		if (
			had_span &&
			wp_html_api_rust_tag_processor_current_span(intern->native, &new_token_start, &new_token_length)
		) {
			wp_html_tag_processor_adjust_bookmarks_after_current_token_update(
				ZEND_THIS,
				(zend_long) old_token_start,
				(zend_long) old_token_length,
				(zend_long) new_token_start,
				(zend_long) new_token_length
			);
		}
		wp_html_tag_processor_sync_html_property(ZEND_THIS, intern->native);
		RETURN_TRUE;
	}

	RETURN_FALSE;
}

PHP_METHOD(WP_HTML_Tag_Processor, get_comment_type)
{
	wp_html_tag_processor_object *intern;
	unsigned char comment_type;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	comment_type = wp_html_api_rust_tag_processor_current_comment_type(intern->native);
	switch (comment_type) {
		case 1:
			RETURN_STRING("COMMENT_AS_ABRUPTLY_CLOSED_COMMENT");
		case 2:
			RETURN_STRING("COMMENT_AS_CDATA_LOOKALIKE");
		case 3:
			RETURN_STRING("COMMENT_AS_HTML_COMMENT");
		case 4:
			RETURN_STRING("COMMENT_AS_PI_NODE_LOOKALIKE");
		case 5:
			RETURN_STRING("COMMENT_AS_INVALID_HTML");
	}

	RETURN_NULL();
}

PHP_METHOD(WP_HTML_Tag_Processor, get_doctype_info)
{
	wp_html_tag_processor_object *intern;
	wp_html_api_rust_byte_slice html;
	size_t token_start;
	size_t token_length;
	zend_string *doctype_class_name;
	zend_class_entry *doctype_ce;
	zval raw_token;
	zval retval;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (
		!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_DOCTYPE", sizeof("STATE_DOCTYPE") - 1) ||
		4 != wp_html_api_rust_tag_processor_current_token_type(intern->native) ||
		!wp_html_api_rust_tag_processor_current_span(intern->native, &token_start, &token_length) ||
		!wp_html_api_rust_tag_processor_get_html(intern->native, &html) ||
		token_start > html.len ||
		token_length > html.len - token_start
	) {
		RETURN_NULL();
	}

	doctype_class_name = zend_string_init("WP_HTML_Doctype_Info", sizeof("WP_HTML_Doctype_Info") - 1, 0);
	doctype_ce = zend_lookup_class(doctype_class_name);
	zend_string_release(doctype_class_name);

	if (NULL == doctype_ce) {
		RETURN_NULL();
	}

	ZVAL_STRINGL(&raw_token, (const char *) html.ptr + token_start, token_length);
	ZVAL_NULL(&retval);

	if (NULL == zend_call_method_with_1_params(NULL, doctype_ce, NULL, "from_doctype_token", &retval, &raw_token)) {
		zval_ptr_dtor(&raw_token);
		RETURN_NULL();
	}

	zval_ptr_dtor(&raw_token);
	RETURN_ZVAL(&retval, 1, 1);
}

PHP_METHOD(WP_HTML_Tag_Processor, set_bookmark)
{
	zend_string *bookmark_name;
	wp_html_tag_processor_object *intern;
	zval rv;
	zval *bookmarks;
	zval span;
	size_t token_start;
	size_t token_length;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(bookmark_name)
	ZEND_PARSE_PARAMETERS_END();

	if (wp_html_tag_processor_parser_state_is_terminal(ZEND_THIS)) {
		RETURN_FALSE;
	}

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (!wp_html_api_rust_tag_processor_current_span(intern->native, &token_start, &token_length)) {
		RETURN_FALSE;
	}

	bookmarks = wp_html_tag_processor_read_bookmarks(ZEND_THIS, &rv);
	if (
		NULL == zend_symtable_find(Z_ARRVAL_P(bookmarks), bookmark_name) &&
		zend_hash_num_elements(Z_ARRVAL_P(bookmarks)) >=
			(uint32_t) wp_html_tag_processor_max_bookmarks(ZEND_THIS)
	) {
		wp_html_api_rust_doing_it_wrong(
			"WP_HTML_Tag_Processor::set_bookmark",
			"Too many bookmarks: cannot create any more.",
			"6.2.0"
		);
		RETURN_FALSE;
	}

	wp_html_tag_processor_create_span(&span, (zend_long) token_start, (zend_long) token_length);
	zend_symtable_update(Z_ARRVAL_P(bookmarks), bookmark_name, &span);

	RETURN_TRUE;
}

PHP_METHOD(WP_HTML_Tag_Processor, release_bookmark)
{
	zend_string *bookmark_name;
	zval rv;
	zval *bookmarks;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(bookmark_name)
	ZEND_PARSE_PARAMETERS_END();

	bookmarks = wp_html_tag_processor_read_bookmarks(ZEND_THIS, &rv);
	RETURN_BOOL(SUCCESS == zend_symtable_del(Z_ARRVAL_P(bookmarks), bookmark_name));
}

PHP_METHOD(WP_HTML_Tag_Processor, has_bookmark)
{
	zend_string *bookmark_name;
	zval rv;
	zval *bookmarks;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(bookmark_name)
	ZEND_PARSE_PARAMETERS_END();

	bookmarks = wp_html_tag_processor_read_bookmarks(ZEND_THIS, &rv);
	RETURN_BOOL(NULL != zend_symtable_find(Z_ARRVAL_P(bookmarks), bookmark_name));
}

PHP_METHOD(WP_HTML_Tag_Processor, seek)
{
	zend_string *bookmark_name;
	wp_html_tag_processor_object *intern;
	zval rv;
	zval *bookmarks;
	zval *bookmark;
	zend_long bookmark_start;
	zend_long bookmark_length;
	size_t token_start;
	size_t token_length;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(bookmark_name)
	ZEND_PARSE_PARAMETERS_END();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (!wp_html_tag_processor_apply_lexical_updates(ZEND_THIS, intern->native)) {
		RETURN_FALSE;
	}

	bookmarks = wp_html_tag_processor_read_bookmarks(ZEND_THIS, &rv);
	bookmark = zend_symtable_find(Z_ARRVAL_P(bookmarks), bookmark_name);
	if (NULL == bookmark) {
		wp_html_api_rust_doing_it_wrong(
			"WP_HTML_Tag_Processor::seek",
			"Unknown bookmark name.",
			"6.2.0"
		);
		RETURN_FALSE;
	}

	if (
		!wp_html_tag_processor_read_long_property(bookmark, "start", sizeof("start") - 1, &bookmark_start) ||
		!wp_html_tag_processor_read_long_property(bookmark, "length", sizeof("length") - 1, &bookmark_length)
	) {
		RETURN_FALSE;
	}

	if (
		wp_html_api_rust_tag_processor_current_span(intern->native, &token_start, &token_length) &&
		token_start == (size_t) bookmark_start &&
		token_length == (size_t) bookmark_length
	) {
		wp_html_tag_processor_update_parser_state_from_native(ZEND_THIS, intern->native);
		RETURN_TRUE;
	}

	if (++intern->seek_count > 1000) {
		wp_html_api_rust_doing_it_wrong(
			"WP_HTML_Tag_Processor::seek",
			"Too many calls to seek() - this can lead to performance issues.",
			"6.2.0"
		);
		RETURN_FALSE;
	}

	if (0 == bookmark_length) {
		wp_html_api_rust_tag_processor_seek(intern->native, (size_t) bookmark_start);
		wp_html_tag_processor_update_parser_state(ZEND_THIS, "STATE_READY");
		RETURN_TRUE;
	}

	wp_html_api_rust_tag_processor_seek(intern->native, (size_t) bookmark_start);
	if (wp_html_api_rust_tag_processor_next_token(intern->native)) {
		wp_html_tag_processor_update_parser_state_from_native(ZEND_THIS, intern->native);
		RETURN_TRUE;
	}

	wp_html_tag_processor_update_parser_state(
		ZEND_THIS,
		wp_html_api_rust_tag_processor_paused_at_incomplete(intern->native) ? "STATE_INCOMPLETE_INPUT" : "STATE_COMPLETE"
	);
	RETURN_FALSE;
}

PHP_METHOD(WP_HTML_Tag_Processor, change_parsing_namespace)
{
	char *new_namespace;
	size_t new_namespace_len;
	wp_html_tag_processor_object *intern;
	unsigned char namespace_id = 0;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STRING(new_namespace, new_namespace_len)
	ZEND_PARSE_PARAMETERS_END();

	if (
		!(
			sizeof("html") - 1 == new_namespace_len &&
			0 == memcmp(new_namespace, "html", sizeof("html") - 1)
		) &&
		!(
			sizeof("math") - 1 == new_namespace_len &&
			0 == memcmp(new_namespace, "math", sizeof("math") - 1)
		) &&
		!(
			sizeof("svg") - 1 == new_namespace_len &&
			0 == memcmp(new_namespace, "svg", sizeof("svg") - 1)
		)
	) {
		RETURN_FALSE;
	}

	zend_update_property_stringl(
		wp_html_tag_processor_ce,
		Z_OBJ_P(ZEND_THIS),
		"parsing_namespace",
		sizeof("parsing_namespace") - 1,
		new_namespace,
		new_namespace_len
	);

	if (!(sizeof("html") - 1 == new_namespace_len && 0 == memcmp(new_namespace, "html", sizeof("html") - 1))) {
		namespace_id = 1;
	}

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL != intern->native) {
		wp_html_api_rust_tag_processor_set_namespace(intern->native, namespace_id);
	}

	RETURN_TRUE;
}

PHP_METHOD(WP_HTML_Tag_Processor, get_namespace)
{
	zval rv;
	zval *namespace_value;

	ZEND_PARSE_PARAMETERS_NONE();

	namespace_value = zend_read_property(
		wp_html_tag_processor_ce,
		Z_OBJ_P(ZEND_THIS),
		"parsing_namespace",
		sizeof("parsing_namespace") - 1,
		1,
		&rv
	);

	if (IS_STRING == Z_TYPE_P(namespace_value)) {
		RETURN_STR_COPY(Z_STR_P(namespace_value));
	}

	RETURN_STRING("html");
}

PHP_METHOD(WP_HTML_Tag_Processor, get_qualified_tag_name)
{
	zval tag_name;
	zval namespace_name;
	zend_string *lower_tag_name;
	zend_string *qualified_tag_name;

	ZEND_PARSE_PARAMETERS_NONE();

	ZVAL_NULL(&tag_name);
	ZVAL_NULL(&namespace_name);

	if (NULL == zend_call_method_with_0_params(Z_OBJ_P(ZEND_THIS), Z_OBJCE_P(ZEND_THIS), NULL, "get_tag", &tag_name)) {
		RETURN_NULL();
	}

	if (EG(exception)) {
		zval_ptr_dtor(&tag_name);
		RETURN_THROWS();
	}

	if (IS_STRING != Z_TYPE(tag_name)) {
		zval_ptr_dtor(&tag_name);
		RETURN_NULL();
	}

	if (NULL == zend_call_method_with_0_params(Z_OBJ_P(ZEND_THIS), Z_OBJCE_P(ZEND_THIS), NULL, "get_namespace", &namespace_name)) {
		zval_ptr_dtor(&tag_name);
		RETURN_NULL();
	}

	if (EG(exception)) {
		zval_ptr_dtor(&namespace_name);
		zval_ptr_dtor(&tag_name);
		RETURN_THROWS();
	}

	if (IS_STRING != Z_TYPE(namespace_name)) {
		zval_ptr_dtor(&namespace_name);
		zval_ptr_dtor(&tag_name);
		RETURN_NULL();
	}

	if (wp_html_api_rust_zend_string_equals_literal(Z_STR(namespace_name), "html", sizeof("html") - 1)) {
		RETVAL_STR_COPY(Z_STR(tag_name));
		zval_ptr_dtor(&namespace_name);
		zval_ptr_dtor(&tag_name);
		return;
	}

	lower_tag_name = wp_html_api_rust_lowercase_ascii_slice(
		(const unsigned char *) Z_STRVAL(tag_name),
		Z_STRLEN(tag_name)
	);
	zval_ptr_dtor(&tag_name);

	if (wp_html_api_rust_zend_string_equals_literal(Z_STR(namespace_name), "svg", sizeof("svg") - 1)) {
		qualified_tag_name = wp_html_api_rust_svg_qualified_tag_name(lower_tag_name);
		zend_string_release(lower_tag_name);
		zval_ptr_dtor(&namespace_name);
		RETURN_STR(qualified_tag_name);
	}

	zval_ptr_dtor(&namespace_name);
	RETURN_STR(lower_tag_name);
}

PHP_METHOD(WP_HTML_Tag_Processor, get_qualified_attribute_name)
{
	char *attribute_name;
	size_t attribute_name_len;
	zval namespace_name;
	zend_string *lower_attribute_name;
	zend_string *qualified_attribute_name = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STRING(attribute_name, attribute_name_len)
	ZEND_PARSE_PARAMETERS_END();

	ZVAL_NULL(&namespace_name);
	if (NULL == zend_call_method_with_0_params(Z_OBJ_P(ZEND_THIS), Z_OBJCE_P(ZEND_THIS), NULL, "get_namespace", &namespace_name)) {
		RETURN_STRINGL(attribute_name, attribute_name_len);
	}

	if (EG(exception)) {
		zval_ptr_dtor(&namespace_name);
		RETURN_THROWS();
	}

	if (
		IS_STRING != Z_TYPE(namespace_name) ||
		wp_html_api_rust_zend_string_equals_literal(Z_STR(namespace_name), "html", sizeof("html") - 1)
	) {
		zval_ptr_dtor(&namespace_name);
		RETURN_STRINGL(attribute_name, attribute_name_len);
	}

	lower_attribute_name = wp_html_api_rust_lowercase_ascii_slice((const unsigned char *) attribute_name, attribute_name_len);

	if (
		wp_html_api_rust_zend_string_equals_literal(Z_STR(namespace_name), "math", sizeof("math") - 1) &&
		wp_html_api_rust_zend_string_equals_literal(lower_attribute_name, "definitionurl", sizeof("definitionurl") - 1)
	) {
		qualified_attribute_name = zend_string_init("definitionURL", sizeof("definitionURL") - 1, 0);
	} else if (wp_html_api_rust_zend_string_equals_literal(Z_STR(namespace_name), "svg", sizeof("svg") - 1)) {
		qualified_attribute_name = wp_html_api_rust_svg_qualified_attribute_name(lower_attribute_name);
	}

	if (NULL == qualified_attribute_name) {
		qualified_attribute_name = wp_html_api_rust_foreign_qualified_attribute_name(lower_attribute_name);
	}

	zend_string_release(lower_attribute_name);
	zval_ptr_dtor(&namespace_name);

	if (NULL != qualified_attribute_name) {
		RETURN_STR(qualified_attribute_name);
	}

	RETURN_STRINGL(attribute_name, attribute_name_len);
}

PHP_METHOD(WP_HTML_Tag_Processor, get_full_comment_text)
{
	wp_html_tag_processor_object *intern;
	wp_html_api_rust_byte_slice text;
	wp_html_api_rust_byte_slice tag_name;
	wp_html_api_rust_byte_slice html;
	size_t token_start;
	size_t token_length;
	unsigned char comment_type;
	zend_string *comment_text;
	size_t offset;
	bool starts_with_question_mark = false;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_FUNKY_COMMENT", sizeof("STATE_FUNKY_COMMENT") - 1)) {
		if (wp_html_api_rust_tag_processor_get_modifiable_text(intern->native, &text)) {
			RETURN_STRINGL((const char *) text.ptr, text.len);
		}

		RETURN_NULL();
	}

	if (!wp_html_tag_processor_parser_state_is(ZEND_THIS, "STATE_COMMENT", sizeof("STATE_COMMENT") - 1)) {
		RETURN_NULL();
	}

	if (!wp_html_api_rust_tag_processor_get_modifiable_text(intern->native, &text)) {
		RETURN_NULL();
	}

	comment_type = wp_html_api_rust_tag_processor_current_comment_type(intern->native);
	switch (comment_type) {
		case 1:
		case 3:
			RETURN_STRINGL((const char *) text.ptr, text.len);

		case 2:
			comment_text = zend_string_alloc(sizeof("[CDATA[") - 1 + text.len + sizeof("]]") - 1, 0);
			memcpy(ZSTR_VAL(comment_text), "[CDATA[", sizeof("[CDATA[") - 1);
			memcpy(ZSTR_VAL(comment_text) + sizeof("[CDATA[") - 1, text.ptr, text.len);
			memcpy(ZSTR_VAL(comment_text) + sizeof("[CDATA[") - 1 + text.len, "]]", sizeof("]]") - 1);
			ZSTR_VAL(comment_text)[ZSTR_LEN(comment_text)] = '\0';
			RETURN_STR(comment_text);

		case 4:
			if (!wp_html_api_rust_tag_processor_get_tag(intern->native, &tag_name)) {
				RETURN_NULL();
			}

			comment_text = zend_string_alloc(1 + tag_name.len + text.len + 1, 0);
			offset = 0;
			ZSTR_VAL(comment_text)[offset++] = '?';
			memcpy(ZSTR_VAL(comment_text) + offset, tag_name.ptr, tag_name.len);
			offset += tag_name.len;
			memcpy(ZSTR_VAL(comment_text) + offset, text.ptr, text.len);
			offset += text.len;
			ZSTR_VAL(comment_text)[offset++] = '?';
			ZSTR_VAL(comment_text)[offset] = '\0';
			RETURN_STR(comment_text);

		case 5:
			if (
				wp_html_api_rust_tag_processor_current_span(intern->native, &token_start, &token_length) &&
				wp_html_api_rust_tag_processor_get_html(intern->native, &html) &&
				token_start < html.len &&
				html.len - token_start > 1 &&
				'?' == html.ptr[token_start + 1]
			) {
				starts_with_question_mark = true;
			}

			if (!starts_with_question_mark) {
				RETURN_STRINGL((const char *) text.ptr, text.len);
			}

			comment_text = zend_string_alloc(1 + text.len, 0);
			ZSTR_VAL(comment_text)[0] = '?';
			memcpy(ZSTR_VAL(comment_text) + 1, text.ptr, text.len);
			ZSTR_VAL(comment_text)[ZSTR_LEN(comment_text)] = '\0';
			RETURN_STR(comment_text);
	}

	RETURN_NULL();
}

PHP_METHOD(WP_HTML_Tag_Processor, get_updated_html)
{
	wp_html_tag_processor_object *intern;
	wp_html_api_rust_byte_slice html;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (!wp_html_tag_processor_apply_lexical_updates(ZEND_THIS, intern->native)) {
		RETURN_EMPTY_STRING();
	}

	if (!wp_html_api_rust_tag_processor_get_html(intern->native, &html)) {
		RETURN_EMPTY_STRING();
	}

	wp_html_tag_processor_sync_html_property(ZEND_THIS, intern->native);
	RETURN_STRINGL((const char *) html.ptr, html.len);
}

PHP_METHOD(WP_HTML_Tag_Processor, __toString)
{
	wp_html_tag_processor_object *intern;
	wp_html_api_rust_byte_slice html;

	ZEND_PARSE_PARAMETERS_NONE();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native) {
		zend_throw_error(NULL, "WP_HTML_Tag_Processor is not initialized");
		RETURN_THROWS();
	}

	if (!wp_html_tag_processor_apply_lexical_updates(ZEND_THIS, intern->native)) {
		RETURN_EMPTY_STRING();
	}

	if (!wp_html_api_rust_tag_processor_get_html(intern->native, &html)) {
		RETURN_EMPTY_STRING();
	}

	RETURN_STRINGL((const char *) html.ptr, html.len);
}

PHP_METHOD(WP_HTML_Processor, __construct)
{
	zval *html_param;
	zval *unlock_param = NULL;
	const char *html = "";
	size_t html_len = 0;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_ZVAL(html_param)
		Z_PARAM_OPTIONAL
		Z_PARAM_ZVAL(unlock_param)
	ZEND_PARSE_PARAMETERS_END();

	if (IS_STRING == Z_TYPE_P(html_param)) {
		html = Z_STRVAL_P(html_param);
		html_len = Z_STRLEN_P(html_param);
	} else {
		wp_html_api_rust_doing_it_wrong(
			"WP_HTML_Processor::__construct",
			"The HTML parameter must be a string.",
			"6.9.0"
		);
	}

	if (!wp_html_tag_processor_initialize(ZEND_THIS, html, html_len)) {
		RETURN_THROWS();
	}
}

static void wp_html_processor_create_initialized(INTERNAL_FUNCTION_PARAMETERS, const char *html, size_t html_len)
{
	zend_class_entry *called_scope = zend_get_called_scope(execute_data);

	if (NULL == called_scope || !instanceof_function(called_scope, wp_html_processor_ce)) {
		called_scope = wp_html_processor_ce;
	}

	object_init_ex(return_value, called_scope);
	if (!wp_html_tag_processor_initialize(return_value, html, html_len)) {
		zval_ptr_dtor(return_value);
		RETURN_THROWS();
	}
}

PHP_METHOD(WP_HTML_Processor, create_fragment)
{
	zval *html_param;
	zval *context_param = NULL;
	zval *encoding_param = NULL;
	const char *html;
	size_t html_len;

	ZEND_PARSE_PARAMETERS_START(1, 3)
		Z_PARAM_ZVAL(html_param)
		Z_PARAM_OPTIONAL
		Z_PARAM_ZVAL(context_param)
		Z_PARAM_ZVAL(encoding_param)
	ZEND_PARSE_PARAMETERS_END();

	if (IS_STRING != Z_TYPE_P(html_param)) {
		wp_html_api_rust_doing_it_wrong(
			"WP_HTML_Processor::create_fragment",
			"The HTML parameter must be a string.",
			"6.9.0"
		);
		RETURN_NULL();
	}

	if (
		NULL != context_param &&
		!(
			IS_STRING == Z_TYPE_P(context_param) &&
			sizeof("<body>") - 1 == Z_STRLEN_P(context_param) &&
			0 == memcmp(Z_STRVAL_P(context_param), "<body>", sizeof("<body>") - 1)
		)
	) {
		RETURN_NULL();
	}

	if (
		NULL != encoding_param &&
		!(
			IS_STRING == Z_TYPE_P(encoding_param) &&
			sizeof("UTF-8") - 1 == Z_STRLEN_P(encoding_param) &&
			0 == memcmp(Z_STRVAL_P(encoding_param), "UTF-8", sizeof("UTF-8") - 1)
		)
	) {
		RETURN_NULL();
	}

	html = Z_STRVAL_P(html_param);
	html_len = Z_STRLEN_P(html_param);
	wp_html_processor_create_initialized(INTERNAL_FUNCTION_PARAM_PASSTHRU, html, html_len);
}

PHP_METHOD(WP_HTML_Processor, create_full_parser)
{
	zval *html_param;
	zval *encoding_param = NULL;
	const char *html;
	size_t html_len;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_ZVAL(html_param)
		Z_PARAM_OPTIONAL
		Z_PARAM_ZVAL(encoding_param)
	ZEND_PARSE_PARAMETERS_END();

	if (IS_STRING != Z_TYPE_P(html_param)) {
		wp_html_api_rust_doing_it_wrong(
			"WP_HTML_Processor::create_full_parser",
			"The HTML parameter must be a string.",
			"6.9.0"
		);
		RETURN_NULL();
	}

	if (
		NULL != encoding_param &&
		!(
			IS_STRING == Z_TYPE_P(encoding_param) &&
			sizeof("UTF-8") - 1 == Z_STRLEN_P(encoding_param) &&
			0 == memcmp(Z_STRVAL_P(encoding_param), "UTF-8", sizeof("UTF-8") - 1)
		)
	) {
		RETURN_NULL();
	}

	html = Z_STRVAL_P(html_param);
	html_len = Z_STRLEN_P(html_param);
	wp_html_processor_create_initialized(INTERNAL_FUNCTION_PARAM_PASSTHRU, html, html_len);
}

PHP_METHOD(WP_HTML_Processor, get_last_error)
{
	ZEND_PARSE_PARAMETERS_NONE();

	RETURN_NULL();
}

PHP_METHOD(WP_HTML_Processor, get_unsupported_exception)
{
	ZEND_PARSE_PARAMETERS_NONE();

	RETURN_NULL();
}

PHP_METHOD(WP_HTML_Processor, is_virtual)
{
	ZEND_PARSE_PARAMETERS_NONE();

	RETURN_FALSE;
}

PHP_METHOD(WP_HTML_Processor, expects_closer)
{
	wp_html_tag_processor_object *intern;
	wp_html_api_rust_byte_slice tag_name;
	zval *node = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_ZVAL(node)
	ZEND_PARSE_PARAMETERS_END();

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL == intern->native || !wp_html_api_rust_tag_processor_get_tag(intern->native, &tag_name)) {
		RETURN_NULL();
	}

	if (wp_html_api_rust_tag_processor_is_tag_closer(intern->native)) {
		RETURN_FALSE;
	}

	if (
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "AREA") ||
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "BASE") ||
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "BR") ||
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "COL") ||
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "EMBED") ||
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "HR") ||
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "IMG") ||
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "INPUT") ||
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "LINK") ||
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "META") ||
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "PARAM") ||
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "SOURCE") ||
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "TRACK") ||
		wp_html_api_rust_ascii_eq_ci(tag_name.ptr, tag_name.len, "WBR")
	) {
		RETURN_FALSE;
	}

	RETURN_TRUE;
}

PHP_METHOD(WP_HTML_Processor, get_breadcrumbs)
{
	wp_html_tag_processor_object *intern;
	wp_html_api_rust_byte_slice tag_name;

	ZEND_PARSE_PARAMETERS_NONE();

	array_init(return_value);
	add_next_index_string(return_value, "HTML");
	add_next_index_string(return_value, "BODY");

	intern = Z_WP_HTML_TAG_PROCESSOR_P(ZEND_THIS);
	if (NULL != intern->native && wp_html_api_rust_tag_processor_get_tag(intern->native, &tag_name)) {
		zend_string *upper = wp_html_api_rust_uppercase_ascii_slice(tag_name.ptr, tag_name.len);
		add_next_index_str(return_value, upper);
	}
}

static const zend_function_entry wp_html_api_rust_functions[] = {
	PHP_FE(wp_html_api_rust_version, arginfo_wp_html_api_rust_version)
	PHP_FE(wp_html_api_rust_scan_next_tag, arginfo_wp_html_api_rust_scan_next_tag)
	PHP_FE_END
};

static const zend_function_entry wp_html_tag_processor_methods[] = {
	PHP_ME(WP_HTML_Tag_Processor, __construct, arginfo_wp_html_tag_processor_construct, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, next_tag, arginfo_wp_html_tag_processor_next_tag, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, next_token, arginfo_wp_html_tag_processor_bool, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, get_tag, arginfo_wp_html_tag_processor_get_tag, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, get_attribute, arginfo_wp_html_tag_processor_get_attribute, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, get_attribute_names_with_prefix, arginfo_wp_html_tag_processor_get_attribute_names_with_prefix, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, set_attribute, arginfo_wp_html_tag_processor_set_attribute, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, remove_attribute, arginfo_wp_html_tag_processor_remove_attribute, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, add_class, arginfo_wp_html_tag_processor_class_mutation, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, remove_class, arginfo_wp_html_tag_processor_class_mutation, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, has_class, arginfo_wp_html_tag_processor_has_class, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, class_list, arginfo_wp_html_tag_processor_class_list, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, is_tag_closer, arginfo_wp_html_tag_processor_bool, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, has_self_closing_flag, arginfo_wp_html_tag_processor_bool, ZEND_ACC_PUBLIC)
		PHP_ME(WP_HTML_Tag_Processor, get_token_name, arginfo_wp_html_tag_processor_nullable_string, ZEND_ACC_PUBLIC)
		PHP_ME(WP_HTML_Tag_Processor, get_token_type, arginfo_wp_html_tag_processor_nullable_string, ZEND_ACC_PUBLIC)
		PHP_ME(WP_HTML_Tag_Processor, paused_at_incomplete_token, arginfo_wp_html_tag_processor_bool, ZEND_ACC_PUBLIC)
		PHP_ME(WP_HTML_Tag_Processor, subdivide_text_appropriately, arginfo_wp_html_tag_processor_bool, ZEND_ACC_PUBLIC)
		PHP_ME(WP_HTML_Tag_Processor, get_modifiable_text, arginfo_wp_html_tag_processor_nullable_string, ZEND_ACC_PUBLIC)
		PHP_ME(WP_HTML_Tag_Processor, native_get_script_content_type, arginfo_wp_html_tag_processor_nullable_string, ZEND_ACC_PROTECTED)
		PHP_ME(WP_HTML_Tag_Processor, set_modifiable_text, arginfo_wp_html_tag_processor_set_modifiable_text, ZEND_ACC_PUBLIC)
		PHP_ME(WP_HTML_Tag_Processor, get_comment_type, arginfo_wp_html_tag_processor_nullable_string, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, get_doctype_info, arginfo_wp_html_tag_processor_nullable_mixed, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, set_bookmark, arginfo_wp_html_tag_processor_bookmark, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, release_bookmark, arginfo_wp_html_tag_processor_bookmark, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, has_bookmark, arginfo_wp_html_tag_processor_bookmark, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, seek, arginfo_wp_html_tag_processor_bookmark, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, change_parsing_namespace, arginfo_wp_html_tag_processor_change_namespace, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, get_namespace, arginfo_wp_html_tag_processor_nullable_string, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, get_qualified_tag_name, arginfo_wp_html_tag_processor_nullable_string, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, get_qualified_attribute_name, arginfo_wp_html_tag_processor_get_attribute, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, get_full_comment_text, arginfo_wp_html_tag_processor_nullable_string, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, get_updated_html, arginfo_wp_html_tag_processor_get_html, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Tag_Processor, __toString, arginfo_wp_html_tag_processor_get_html, ZEND_ACC_PUBLIC)
	PHP_FE_END
};

static const zend_function_entry wp_html_processor_methods[] = {
	PHP_ME(WP_HTML_Processor, __construct, arginfo_wp_html_processor_construct, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Processor, create_fragment, arginfo_wp_html_processor_create_fragment, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
	PHP_ME(WP_HTML_Processor, create_full_parser, arginfo_wp_html_processor_create_full_parser, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
	PHP_ME(WP_HTML_Processor, get_last_error, arginfo_wp_html_tag_processor_nullable_string, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Processor, get_unsupported_exception, arginfo_wp_html_tag_processor_nullable_mixed, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Processor, is_virtual, arginfo_wp_html_tag_processor_bool, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Processor, expects_closer, arginfo_wp_html_tag_processor_nullable_mixed, ZEND_ACC_PUBLIC)
	PHP_ME(WP_HTML_Processor, get_breadcrumbs, arginfo_wp_html_tag_processor_class_list, ZEND_ACC_PUBLIC)
	PHP_FE_END
};

static void wp_html_api_rust_register_tag_processor_class(void)
{
	zend_class_entry ce;

	INIT_CLASS_ENTRY(ce, "WP_HTML_Tag_Processor_Native", wp_html_tag_processor_methods);
	wp_html_tag_processor_ce = zend_register_internal_class(&ce);
	wp_html_tag_processor_ce->create_object = wp_html_tag_processor_create_object;

	zend_declare_class_constant_long(wp_html_tag_processor_ce, "MAX_BOOKMARKS", sizeof("MAX_BOOKMARKS") - 1, 10);
	zend_declare_class_constant_long(wp_html_tag_processor_ce, "MAX_SEEK_OPS", sizeof("MAX_SEEK_OPS") - 1, 1000);
	zend_declare_class_constant_bool(wp_html_tag_processor_ce, "ADD_CLASS", sizeof("ADD_CLASS") - 1, true);
	zend_declare_class_constant_bool(wp_html_tag_processor_ce, "REMOVE_CLASS", sizeof("REMOVE_CLASS") - 1, false);
	zend_declare_class_constant_null(wp_html_tag_processor_ce, "SKIP_CLASS", sizeof("SKIP_CLASS") - 1);
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "STATE_READY", sizeof("STATE_READY") - 1, "STATE_READY");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "STATE_COMPLETE", sizeof("STATE_COMPLETE") - 1, "STATE_COMPLETE");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "STATE_INCOMPLETE_INPUT", sizeof("STATE_INCOMPLETE_INPUT") - 1, "STATE_INCOMPLETE_INPUT");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "STATE_MATCHED_TAG", sizeof("STATE_MATCHED_TAG") - 1, "STATE_MATCHED_TAG");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "STATE_TEXT_NODE", sizeof("STATE_TEXT_NODE") - 1, "STATE_TEXT_NODE");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "STATE_CDATA_NODE", sizeof("STATE_CDATA_NODE") - 1, "STATE_CDATA_NODE");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "STATE_COMMENT", sizeof("STATE_COMMENT") - 1, "STATE_COMMENT");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "STATE_DOCTYPE", sizeof("STATE_DOCTYPE") - 1, "STATE_DOCTYPE");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "STATE_PRESUMPTUOUS_TAG", sizeof("STATE_PRESUMPTUOUS_TAG") - 1, "STATE_PRESUMPTUOUS_TAG");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "STATE_FUNKY_COMMENT", sizeof("STATE_FUNKY_COMMENT") - 1, "STATE_WP_FUNKY");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "COMMENT_AS_ABRUPTLY_CLOSED_COMMENT", sizeof("COMMENT_AS_ABRUPTLY_CLOSED_COMMENT") - 1, "COMMENT_AS_ABRUPTLY_CLOSED_COMMENT");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "COMMENT_AS_CDATA_LOOKALIKE", sizeof("COMMENT_AS_CDATA_LOOKALIKE") - 1, "COMMENT_AS_CDATA_LOOKALIKE");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "COMMENT_AS_HTML_COMMENT", sizeof("COMMENT_AS_HTML_COMMENT") - 1, "COMMENT_AS_HTML_COMMENT");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "COMMENT_AS_PI_NODE_LOOKALIKE", sizeof("COMMENT_AS_PI_NODE_LOOKALIKE") - 1, "COMMENT_AS_PI_NODE_LOOKALIKE");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "COMMENT_AS_INVALID_HTML", sizeof("COMMENT_AS_INVALID_HTML") - 1, "COMMENT_AS_INVALID_HTML");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "NO_QUIRKS_MODE", sizeof("NO_QUIRKS_MODE") - 1, "no-quirks-mode");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "QUIRKS_MODE", sizeof("QUIRKS_MODE") - 1, "quirks-mode");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "TEXT_IS_GENERIC", sizeof("TEXT_IS_GENERIC") - 1, "TEXT_IS_GENERIC");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "TEXT_IS_NULL_SEQUENCE", sizeof("TEXT_IS_NULL_SEQUENCE") - 1, "TEXT_IS_NULL_SEQUENCE");
	zend_declare_class_constant_string(wp_html_tag_processor_ce, "TEXT_IS_WHITESPACE", sizeof("TEXT_IS_WHITESPACE") - 1, "TEXT_IS_WHITESPACE");

	zend_declare_property_null(wp_html_tag_processor_ce, "html", sizeof("html") - 1, ZEND_ACC_PROTECTED);
	zend_declare_property_string(wp_html_tag_processor_ce, "parser_state", sizeof("parser_state") - 1, "STATE_READY", ZEND_ACC_PROTECTED);
	zend_declare_property_string(wp_html_tag_processor_ce, "compat_mode", sizeof("compat_mode") - 1, "no-quirks-mode", ZEND_ACC_PROTECTED);
	zend_declare_property_string(wp_html_tag_processor_ce, "parsing_namespace", sizeof("parsing_namespace") - 1, "html", ZEND_ACC_PRIVATE);
	zend_declare_property_null(wp_html_tag_processor_ce, "comment_type", sizeof("comment_type") - 1, ZEND_ACC_PROTECTED);
	zend_declare_property_string(wp_html_tag_processor_ce, "text_node_classification", sizeof("text_node_classification") - 1, "TEXT_IS_GENERIC", ZEND_ACC_PROTECTED);
	zend_declare_property_null(wp_html_tag_processor_ce, "bookmarks", sizeof("bookmarks") - 1, ZEND_ACC_PROTECTED);
	zend_declare_property_null(wp_html_tag_processor_ce, "lexical_updates", sizeof("lexical_updates") - 1, ZEND_ACC_PROTECTED);

	memcpy(&wp_html_tag_processor_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
	wp_html_tag_processor_handlers.offset = XtOffsetOf(wp_html_tag_processor_object, std);
	wp_html_tag_processor_handlers.free_obj = wp_html_tag_processor_free_obj;
}

static void wp_html_api_rust_register_processor_class(void)
{
	zend_class_entry ce;

	INIT_CLASS_ENTRY(ce, "WP_HTML_Processor", wp_html_processor_methods);
	wp_html_processor_ce = zend_register_internal_class_ex(&ce, wp_html_tag_processor_ce);
	wp_html_processor_ce->create_object = wp_html_tag_processor_create_object;
}

PHP_MINIT_FUNCTION(wp_html_api_rust)
{
	REGISTER_INI_ENTRIES();

	if (INI_BOOL("wp_html_api_rust.replace_html_api")) {
		wp_html_api_rust_register_tag_processor_class();
	}

	return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(wp_html_api_rust)
{
	UNREGISTER_INI_ENTRIES();

	return SUCCESS;
}

PHP_MINFO_FUNCTION(wp_html_api_rust)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "wp_html_api_rust support", "enabled");
	php_info_print_table_row(2, "extension version", PHP_WP_HTML_API_RUST_VERSION);
	php_info_print_table_row(2, "rust core version", wp_html_api_rust_core_version());
	php_info_print_table_row(2, "replace HTML API classes", INI_BOOL("wp_html_api_rust.replace_html_api") ? "enabled" : "disabled");
	php_info_print_table_end();
}

zend_module_entry wp_html_api_rust_module_entry = {
	STANDARD_MODULE_HEADER,
	"wp_html_api_rust",
	wp_html_api_rust_functions,
	PHP_MINIT(wp_html_api_rust),
	PHP_MSHUTDOWN(wp_html_api_rust),
	NULL,
	NULL,
	PHP_MINFO(wp_html_api_rust),
	PHP_WP_HTML_API_RUST_VERSION,
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_WP_HTML_API_RUST
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(wp_html_api_rust)
#endif
