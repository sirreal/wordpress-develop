use std::ffi::c_char;
use std::ptr;
use std::slice;

mod html5_named_character_references;

static VERSION: &[u8] = b"0.1.0\0";

const TOKEN_TYPE_TAG: u8 = 1;
const TOKEN_TYPE_TEXT: u8 = 2;
const TOKEN_TYPE_COMMENT: u8 = 3;
const TOKEN_TYPE_DOCTYPE: u8 = 4;
const TOKEN_TYPE_CDATA: u8 = 5;
const TOKEN_TYPE_PRESUMPTUOUS_TAG: u8 = 6;
const TOKEN_TYPE_FUNKY_COMMENT: u8 = 7;

const NAMESPACE_HTML: u8 = 0;
const NAMESPACE_FOREIGN: u8 = 1;

const COMMENT_TYPE_NONE: u8 = 0;
const COMMENT_TYPE_ABRUPTLY_CLOSED: u8 = 1;
const COMMENT_TYPE_CDATA_LOOKALIKE: u8 = 2;
const COMMENT_TYPE_HTML: u8 = 3;
const COMMENT_TYPE_PI_LOOKALIKE: u8 = 4;
const COMMENT_TYPE_INVALID: u8 = 5;

#[repr(C)]
#[derive(Clone, Copy, Debug, Default, Eq, PartialEq)]
pub struct TagScan {
    pub tag_start: usize,
    pub tag_end: usize,
    pub name_start: usize,
    pub name_len: usize,
    pub is_closing: bool,
    pub has_self_closing_flag: bool,
    pub token_end: usize,
    pub token_type: u8,
}

#[repr(C)]
#[derive(Clone, Copy, Debug, Default)]
pub struct ByteSlice {
    pub ptr: *const u8,
    pub len: usize,
}

pub struct TagProcessor {
    html: Vec<u8>,
    offset: usize,
    current: Option<TagScan>,
    scratch: Vec<u8>,
    paused_at_incomplete: bool,
    inserted_attributes: Vec<Vec<u8>>,
    parsing_namespace: u8,
}

#[no_mangle]
pub extern "C" fn wp_html_api_rust_alloc(len: usize) -> *mut u8 {
    let mut buffer = Vec::<u8>::with_capacity(len);
    let ptr = buffer.as_mut_ptr();
    std::mem::forget(buffer);
    ptr
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_dealloc(ptr: *mut u8, len: usize) {
    if !ptr.is_null() {
        drop(Vec::from_raw_parts(ptr, 0, len));
    }
}

#[derive(Clone, Copy, Debug)]
struct AttributeSpan {
    name_start: usize,
    full_end: usize,
    value: Option<(usize, usize)>,
}

#[no_mangle]
pub extern "C" fn wp_html_api_rust_core_version() -> *const c_char {
    VERSION.as_ptr().cast()
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_decoder_decode(
    context: u8,
    text: *const u8,
    text_len: usize,
    out_ptr: *mut u8,
    out_capacity: usize,
    out_len: *mut usize,
) -> bool {
    if (text.is_null() && text_len > 0) || out_ptr.is_null() || out_len.is_null() {
        return false;
    }

    let text = if text_len == 0 {
        &[][..]
    } else {
        slice::from_raw_parts(text, text_len)
    };
    let decoded = decode_html_text(decode_context_from_byte(context), text);
    write_output_buffer(&decoded, out_ptr, out_capacity, out_len)
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_decoder_read_character_reference(
    context: u8,
    text: *const u8,
    text_len: usize,
    at: usize,
    out_ptr: *mut u8,
    out_capacity: usize,
    out_len: *mut usize,
    match_len: *mut usize,
) -> bool {
    if (text.is_null() && text_len > 0)
        || out_ptr.is_null()
        || out_len.is_null()
        || match_len.is_null()
    {
        return false;
    }

    let text = if text_len == 0 {
        &[][..]
    } else {
        slice::from_raw_parts(text, text_len)
    };

    if at >= text.len() {
        return false;
    }

    let Some((decoded, consumed)) =
        decode_character_reference(decode_context_from_byte(context), &text[at..])
    else {
        return false;
    };

    let mut output = Vec::with_capacity(consumed);
    decoded.append_to(&mut output);
    if !write_output_buffer(&output, out_ptr, out_capacity, out_len) {
        return false;
    }

    ptr::write(match_len, consumed);
    true
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_decoder_attribute_starts_with(
    haystack: *const u8,
    haystack_len: usize,
    search_text: *const u8,
    search_text_len: usize,
    ascii_case_insensitive: bool,
) -> bool {
    if (haystack.is_null() && haystack_len > 0) || (search_text.is_null() && search_text_len > 0) {
        return false;
    }

    let haystack = if haystack_len == 0 {
        &[][..]
    } else {
        slice::from_raw_parts(haystack, haystack_len)
    };
    let search_text = if search_text_len == 0 {
        &[][..]
    } else {
        slice::from_raw_parts(search_text, search_text_len)
    };

    attribute_starts_with(haystack, search_text, ascii_case_insensitive)
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_decoder_code_point_to_utf8_bytes(
    code_point: u32,
    out_ptr: *mut u8,
    out_capacity: usize,
    out_len: *mut usize,
) -> bool {
    if out_ptr.is_null() || out_len.is_null() {
        return false;
    }

    let character = char::from_u32(code_point).unwrap_or('\u{FFFD}');
    let mut buffer = [0; 4];
    write_output_buffer(
        character.encode_utf8(&mut buffer).as_bytes(),
        out_ptr,
        out_capacity,
        out_len,
    )
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_scan_next_tag(
    html: *const u8,
    len: usize,
    offset: usize,
    out: *mut TagScan,
) -> bool {
    if html.is_null() || out.is_null() {
        return false;
    }

    let html = slice::from_raw_parts(html, len);

    match scan_next_tag(html, offset) {
        Some(scan) => {
            ptr::write(out, scan);
            true
        }
        None => false,
    }
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_new(
    html: *const u8,
    len: usize,
) -> *mut TagProcessor {
    if html.is_null() && len > 0 {
        return ptr::null_mut();
    }

    let html = if len == 0 {
        Vec::new()
    } else {
        slice::from_raw_parts(html, len).to_vec()
    };

    Box::into_raw(Box::new(TagProcessor {
        html,
        offset: 0,
        current: None,
        scratch: Vec::new(),
        paused_at_incomplete: false,
        inserted_attributes: Vec::new(),
        parsing_namespace: NAMESPACE_HTML,
    }))
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_free(processor: *mut TagProcessor) {
    if !processor.is_null() {
        drop(Box::from_raw(processor));
    }
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_next_tag(
    processor: *mut TagProcessor,
    query: *const u8,
    query_len: usize,
    visit_closers: bool,
) -> bool {
    let Some(processor) = processor.as_mut() else {
        return false;
    };

    let query = if query.is_null() {
        None
    } else {
        Some(slice::from_raw_parts(query, query_len))
    };

    processor.paused_at_incomplete = false;
    processor.inserted_attributes.clear();

    loop {
        let scan = match scan_next_token_in_namespace(
            &processor.html,
            processor.offset,
            processor.parsing_namespace,
        ) {
            ScanResult::Token(scan) => scan,
            ScanResult::Incomplete => {
                processor.paused_at_incomplete = true;
                return false;
            }
            ScanResult::None => {
                return false;
            }
        };

        processor.offset = scan.token_end;

        if scan.token_type != TOKEN_TYPE_TAG {
            continue;
        }

        if scan.is_closing && !visit_closers {
            continue;
        }

        if let Some(query) = query {
            let tag_name = &processor.html[scan.name_start..scan.name_start + scan.name_len];
            if tag_name.len() != query.len() || !eq_ignore_ascii_case(tag_name, query) {
                continue;
            }
        }

        processor.current = Some(scan);
        return true;
    }
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_next_token(
    processor: *mut TagProcessor,
) -> bool {
    let Some(processor) = processor.as_mut() else {
        return false;
    };

    processor.paused_at_incomplete = false;
    processor.inserted_attributes.clear();

    match scan_next_token_in_namespace(
        &processor.html,
        processor.offset,
        processor.parsing_namespace,
    ) {
        ScanResult::Token(scan) => {
            processor.offset = scan.token_end;
            processor.current = Some(scan);
            true
        }
        ScanResult::Incomplete => {
            processor.paused_at_incomplete = true;
            false
        }
        ScanResult::None => false,
    }
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_seek(
    processor: *mut TagProcessor,
    offset: usize,
) {
    let Some(processor) = processor.as_mut() else {
        return;
    };

    processor.offset = offset.min(processor.html.len());
    processor.current = None;
    processor.paused_at_incomplete = false;
    processor.inserted_attributes.clear();
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_set_namespace(
    processor: *mut TagProcessor,
    namespace: u8,
) {
    let Some(processor) = processor.as_mut() else {
        return;
    };

    processor.parsing_namespace = if namespace == NAMESPACE_FOREIGN {
        NAMESPACE_FOREIGN
    } else {
        NAMESPACE_HTML
    };
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_apply_lexical_update(
    processor: *mut TagProcessor,
    start: usize,
    length: usize,
    replacement: *const u8,
    replacement_len: usize,
) -> bool {
    let Some(processor) = processor.as_mut() else {
        return false;
    };

    if replacement.is_null() && replacement_len > 0 {
        return false;
    }

    let Some(end) = start.checked_add(length) else {
        return false;
    };

    if end > processor.html.len() {
        return false;
    }

    let replacement = if replacement_len == 0 {
        &[]
    } else {
        slice::from_raw_parts(replacement, replacement_len)
    };

    processor.replace_range_preserving_cursor_at_inserted_start(start, end, replacement);
    true
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_current_span(
    processor: *const TagProcessor,
    start: *mut usize,
    length: *mut usize,
) -> bool {
    let Some(processor) = processor.as_ref() else {
        return false;
    };

    let Some(scan) = processor.current else {
        return false;
    };

    if start.is_null() || length.is_null() {
        return false;
    }

    ptr::write(start, scan.tag_start);
    ptr::write(length, scan.token_end - scan.tag_start);
    true
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_current_token_type(
    processor: *const TagProcessor,
) -> u8 {
    let Some(processor) = processor.as_ref() else {
        return 0;
    };

    processor.current.map(|scan| scan.token_type).unwrap_or(0)
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_paused_at_incomplete(
    processor: *const TagProcessor,
) -> bool {
    let Some(processor) = processor.as_ref() else {
        return false;
    };

    processor.paused_at_incomplete
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_subdivide_text_appropriately(
    processor: *mut TagProcessor,
) -> u8 {
    let Some(processor) = processor.as_mut() else {
        return 0;
    };

    processor.subdivide_current_text()
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_get_modifiable_text(
    processor: *mut TagProcessor,
    out: *mut ByteSlice,
) -> bool {
    let Some(processor) = processor.as_mut() else {
        return false;
    };

    let Some(scan) = processor.current else {
        return false;
    };

    if out.is_null() {
        return false;
    }

    let Some(text) = processor.current_modifiable_text(scan) else {
        return false;
    };

    processor.scratch = text;
    ptr::write(
        out,
        ByteSlice {
            ptr: processor.scratch.as_ptr(),
            len: processor.scratch.len(),
        },
    );
    true
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_set_modifiable_text(
    processor: *mut TagProcessor,
    text: *const u8,
    text_len: usize,
) -> bool {
    let Some(processor) = processor.as_mut() else {
        return false;
    };

    if text.is_null() && text_len > 0 {
        return false;
    }

    let replacement = if text_len == 0 {
        &[]
    } else {
        slice::from_raw_parts(text, text_len)
    };

    processor.set_modifiable_text(replacement)
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_current_comment_type(
    processor: *const TagProcessor,
) -> u8 {
    let Some(processor) = processor.as_ref() else {
        return COMMENT_TYPE_NONE;
    };

    let Some(scan) = processor.current else {
        return COMMENT_TYPE_NONE;
    };

    processor.comment_type(scan)
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_script_content_type(
    processor: *const TagProcessor,
) -> u8 {
    let Some(processor) = processor.as_ref() else {
        return 0;
    };

    let Some(scan) = processor.current else {
        return 0;
    };

    if processor.parsing_namespace != NAMESPACE_HTML || scan.token_type != TOKEN_TYPE_TAG || scan.is_closing {
        return 0;
    }

    let tag_name = &processor.html[scan.name_start..scan.name_start + scan.name_len];
    if !eq_ignore_ascii_case(tag_name, b"SCRIPT") {
        return 0;
    }

    match processor.script_content_type(scan) {
        ScriptContentType::JavaScript => 1,
        ScriptContentType::Json => 2,
        ScriptContentType::Other => 0,
    }
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_get_tag(
    processor: *const TagProcessor,
    out: *mut ByteSlice,
) -> bool {
    let Some(processor) = processor.as_ref() else {
        return false;
    };

    let Some(scan) = processor.current else {
        return false;
    };

    if scan.token_type == TOKEN_TYPE_COMMENT {
        let Some((target_start, target_end)) = pi_target_span(&processor.html, scan) else {
            return false;
        };

        ptr::write(
            out,
            ByteSlice {
                ptr: processor.html.as_ptr().add(target_start),
                len: target_end - target_start,
            },
        );
        return true;
    }

    if scan.token_type != TOKEN_TYPE_TAG {
        return false;
    }

    ptr::write(
        out,
        ByteSlice {
            ptr: processor.html.as_ptr().add(scan.name_start),
            len: scan.name_len,
        },
    );

    true
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_is_tag_closer(
    processor: *const TagProcessor,
) -> bool {
    let Some(processor) = processor.as_ref() else {
        return false;
    };

    let Some(scan) = processor.current else {
        return false;
    };

    if scan.token_type != TOKEN_TYPE_TAG {
        return false;
    }

    scan.is_closing
        && !eq_ignore_ascii_case(&processor.html[scan.name_start..scan.name_start + scan.name_len], b"BR")
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_has_self_closing_flag(
    processor: *const TagProcessor,
) -> bool {
    let Some(processor) = processor.as_ref() else {
        return false;
    };

    processor
        .current
        .filter(|scan| scan.token_type == TOKEN_TYPE_TAG)
        .map(|scan| scan.has_self_closing_flag)
        .unwrap_or(false)
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_get_attribute(
    processor: *mut TagProcessor,
    name: *const u8,
    name_len: usize,
    out: *mut ByteSlice,
) -> u8 {
    let Some(processor) = processor.as_mut() else {
        return 0;
    };

    if name.is_null() || out.is_null() {
        return 0;
    }

    let name = slice::from_raw_parts(name, name_len);
    match processor.get_attribute(name) {
        AttributeValue::Missing => 0,
        AttributeValue::Boolean => 1,
        AttributeValue::String => {
            ptr::write(
                out,
                ByteSlice {
                    ptr: processor.scratch.as_ptr(),
                    len: processor.scratch.len(),
                },
            );
            2
        }
    }
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_get_attribute_names_with_prefix(
    processor: *mut TagProcessor,
    prefix: *const u8,
    prefix_len: usize,
    out: *mut ByteSlice,
) -> u8 {
    let Some(processor) = processor.as_mut() else {
        return 0;
    };

    if prefix.is_null() || out.is_null() {
        return 0;
    }

    let prefix = slice::from_raw_parts(prefix, prefix_len);
    if !processor.get_attribute_names_with_prefix(prefix) {
        return 0;
    }

    ptr::write(
        out,
        ByteSlice {
            ptr: processor.scratch.as_ptr(),
            len: processor.scratch.len(),
        },
    );
    1
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_set_attribute(
    processor: *mut TagProcessor,
    name: *const u8,
    name_len: usize,
    value: *const u8,
    value_len: usize,
    value_kind: u8,
) -> bool {
    let Some(processor) = processor.as_mut() else {
        return false;
    };

    if name.is_null() {
        return false;
    }

    let name = slice::from_raw_parts(name, name_len);
    let value = if value.is_null() {
        &[][..]
    } else {
        slice::from_raw_parts(value, value_len)
    };

    processor.set_attribute(name, value, value_kind)
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_remove_attribute(
    processor: *mut TagProcessor,
    name: *const u8,
    name_len: usize,
) -> bool {
    let Some(processor) = processor.as_mut() else {
        return false;
    };

    if name.is_null() {
        return false;
    }

    let name = slice::from_raw_parts(name, name_len);
    processor.remove_attribute(name)
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_add_class(
    processor: *mut TagProcessor,
    class_name: *const u8,
    class_name_len: usize,
    quirks_mode: bool,
) -> bool {
    let Some(processor) = processor.as_mut() else {
        return false;
    };

    if class_name.is_null() {
        return false;
    }

    let class_name = slice::from_raw_parts(class_name, class_name_len);
    processor.add_class(class_name, quirks_mode)
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_remove_class(
    processor: *mut TagProcessor,
    class_name: *const u8,
    class_name_len: usize,
    quirks_mode: bool,
) -> bool {
    let Some(processor) = processor.as_mut() else {
        return false;
    };

    if class_name.is_null() {
        return false;
    }

    let class_name = slice::from_raw_parts(class_name, class_name_len);
    processor.remove_class(class_name, quirks_mode)
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_has_class(
    processor: *mut TagProcessor,
    class_name: *const u8,
    class_name_len: usize,
    quirks_mode: bool,
) -> u8 {
    let Some(processor) = processor.as_mut() else {
        return 0;
    };

    if class_name.is_null() {
        return 0;
    }

    let class_name = slice::from_raw_parts(class_name, class_name_len);
    processor.has_class(class_name, quirks_mode)
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_class_list(
    processor: *mut TagProcessor,
    out: *mut ByteSlice,
    quirks_mode: bool,
) -> u8 {
    let Some(processor) = processor.as_mut() else {
        return 0;
    };

    if out.is_null() || !processor.class_list(quirks_mode) {
        return 0;
    }

    ptr::write(
        out,
        ByteSlice {
            ptr: processor.scratch.as_ptr(),
            len: processor.scratch.len(),
        },
    );
    1
}

#[no_mangle]
pub unsafe extern "C" fn wp_html_api_rust_tag_processor_get_html(
    processor: *const TagProcessor,
    out: *mut ByteSlice,
) -> bool {
    let Some(processor) = processor.as_ref() else {
        return false;
    };

    ptr::write(
        out,
        ByteSlice {
            ptr: processor.html.as_ptr(),
            len: processor.html.len(),
        },
    );

    true
}

enum AttributeValue {
    Missing,
    Boolean,
    String,
}

struct ClassEntry {
    name: Vec<u8>,
    comparable: Vec<u8>,
}

impl TagProcessor {
    fn current_modifiable_text(&self, scan: TagScan) -> Option<Vec<u8>> {
        match scan.token_type {
            TOKEN_TYPE_TEXT => {
                let mut raw = &self.html[scan.tag_start..scan.token_end];
                if self.text_follows_pre_or_listing(scan.tag_start) {
                    raw = strip_initial_newline(raw);
                }
                let null_transform = if self.parsing_namespace == NAMESPACE_HTML {
                    NullTransform::Remove
                } else {
                    NullTransform::Replace
                };
                Some(transform_text(raw, true, null_transform))
            }
            TOKEN_TYPE_CDATA if scan.token_end >= scan.tag_start + 9 => {
                let token = &self.html[scan.tag_start..scan.token_end];
                let text_end = if token.ends_with(b"]]>") {
                    scan.token_end - 3
                } else {
                    scan.token_end
                };
                Some(transform_text(
                    &self.html[scan.tag_start + 9..text_end],
                    false,
                    NullTransform::Replace,
                ))
            }
            TOKEN_TYPE_DOCTYPE if scan.token_end > scan.tag_start + 9 => {
                Some(self.html[scan.tag_start + 9..scan.token_end - 1].to_vec())
            }
            TOKEN_TYPE_COMMENT => self.comment_modifiable_text(scan),
            TOKEN_TYPE_FUNKY_COMMENT => {
                let text_end = if self.html.get(scan.token_end.saturating_sub(1)) == Some(&b'>') {
                    scan.token_end - 1
                } else {
                    scan.token_end
                };
                Some(transform_text(
                    &self.html[scan.tag_start + 2..text_end],
                    false,
                    NullTransform::Replace,
                ))
            }
            TOKEN_TYPE_TAG if scan.token_end > scan.tag_end => {
                let inner = &self.html[scan.tag_end..scan.token_end];
                let tag_name = &self.html[scan.name_start..scan.name_start + scan.name_len];
                let relative = if find_special_closer(&self.html[..scan.token_end], scan.tag_end, tag_name).is_some() {
                    find_last_subslice(inner, b"</")?
                } else {
                    unclosed_atomic_text_end(&self.html, scan.tag_end, scan.token_end, tag_name) - scan.tag_end
                };
                let mut raw = &self.html[scan.tag_end..scan.tag_end + relative];

                if matches_ignore_ascii_case(tag_name, &[&b"TEXTAREA"[..]]) {
                    raw = strip_initial_newline(raw);
                    return Some(transform_text(raw, true, NullTransform::Replace));
                }

                if matches_ignore_ascii_case(tag_name, &[&b"TITLE"[..]]) {
                    return Some(transform_text(raw, true, NullTransform::Replace));
                }

                Some(transform_text(raw, false, NullTransform::Replace))
            }
            _ => None,
        }
    }

    fn comment_modifiable_text(&self, scan: TagScan) -> Option<Vec<u8>> {
        let token = &self.html[scan.tag_start..scan.token_end];

        if token.starts_with(b"<!--") {
            let body_start = scan.tag_start + 4;
            let mut end = if token.ends_with(b"--!>") {
                scan.token_end.saturating_sub(4)
            } else if token.ends_with(b"-->") {
                scan.token_end.saturating_sub(3)
            } else if token.ends_with(b">") {
                scan.token_end.saturating_sub(1)
            } else {
                scan.token_end
            };
            if end < body_start {
                end = body_start;
            }
            return Some(transform_text(
                &self.html[body_start..end],
                false,
                NullTransform::Replace,
            ));
        }

        if starts_with_ignore_ascii_case(token, b"<![CDATA[") {
            if token.ends_with(b"]]>") {
                return Some(transform_text(
                    &self.html[scan.tag_start + 9..scan.token_end - 3],
                    false,
                    NullTransform::Replace,
                ));
            }
        }

        if token.starts_with(b"<?") && token.ends_with(b"?>") {
            if let Some((_target_start, target_end)) = pi_target_span(&self.html, scan) {
                let text_end = scan.token_end.saturating_sub(2);
                return Some(transform_text(
                    &self.html[target_end..text_end],
                    false,
                    NullTransform::Replace,
                ));
            }
        }

        let text_end = if self.html.get(scan.token_end.saturating_sub(1)) == Some(&b'>') {
            scan.token_end - 1
        } else {
            scan.token_end
        };
        Some(transform_text(
            &self.html[scan.tag_start + 2..text_end],
            false,
            NullTransform::Replace,
        ))
    }

    fn comment_type(&self, scan: TagScan) -> u8 {
        if scan.token_type == TOKEN_TYPE_FUNKY_COMMENT {
            return COMMENT_TYPE_INVALID;
        }

        if scan.token_type != TOKEN_TYPE_COMMENT {
            return COMMENT_TYPE_NONE;
        }

        let token = &self.html[scan.tag_start..scan.token_end];
        if token.starts_with(b"<!--") {
            if token.ends_with(b"--!>") || token.ends_with(b"<!-->") || token.ends_with(b"<!--->") {
                return COMMENT_TYPE_ABRUPTLY_CLOSED;
            }
            return COMMENT_TYPE_HTML;
        }

        if starts_with_ignore_ascii_case(token, b"<![CDATA[") {
            if token.ends_with(b"]]>") {
                return COMMENT_TYPE_CDATA_LOOKALIKE;
            }
            return COMMENT_TYPE_INVALID;
        }

        if token.starts_with(b"<?") {
            if pi_target_span(&self.html, scan).is_some() && token.ends_with(b"?>") {
                return COMMENT_TYPE_PI_LOOKALIKE;
            }
            return COMMENT_TYPE_INVALID;
        }

        COMMENT_TYPE_INVALID
    }

    fn text_follows_pre_or_listing(&self, text_start: usize) -> bool {
        if text_start == 0 || self.html[text_start - 1] != b'>' {
            return false;
        }

        let Some(tag_start) = self.html[..text_start].iter().rposition(|&byte| byte == b'<') else {
            return false;
        };

        let ScanResult::Token(scan) = scan_next_token_in_namespace(&self.html, tag_start, self.parsing_namespace) else {
            return false;
        };

        if scan.token_type != TOKEN_TYPE_TAG || scan.is_closing || scan.tag_end != text_start {
            return false;
        }

        matches_ignore_ascii_case(
            &self.html[scan.name_start..scan.name_start + scan.name_len],
            &[&b"PRE"[..], &b"LISTING"[..]],
        )
    }

    fn subdivide_current_text(&mut self) -> u8 {
        const TEXT_IS_GENERIC: u8 = 0;
        const TEXT_IS_NULL_SEQUENCE: u8 = 1;
        const TEXT_IS_WHITESPACE: u8 = 2;

        let Some(scan) = self.current else {
            return TEXT_IS_GENERIC;
        };

        if scan.token_type != TOKEN_TYPE_TEXT || scan.tag_start >= scan.token_end {
            return TEXT_IS_GENERIC;
        }

        let mut at = scan.tag_start;
        while at < scan.token_end && self.html[at] == 0 {
            at += 1;
        }

        if at > scan.tag_start {
            self.truncate_current_text(at);
            return TEXT_IS_NULL_SEQUENCE;
        }

        while at < scan.token_end {
            while at < scan.token_end && is_html_whitespace(self.html[at]) {
                at += 1;
            }

            if at < scan.token_end && self.html[at] == b'&' {
                if let Some((decoded, consumed)) =
                    decode_character_reference(DecodeContext::Data, &self.html[at..scan.token_end])
                {
                    if decoded.is_html_whitespace() {
                        at += consumed;
                        continue;
                    }
                }
            }

            break;
        }

        if at > scan.tag_start {
            self.truncate_current_text(at);
            return TEXT_IS_WHITESPACE;
        }

        TEXT_IS_GENERIC
    }

    fn truncate_current_text(&mut self, end: usize) {
        if let Some(scan) = self.current.as_mut() {
            scan.tag_end = end;
            scan.token_end = end;
        }
        self.offset = end;
    }

    fn set_modifiable_text(&mut self, plaintext: &[u8]) -> bool {
        let Some(scan) = self.current else {
            return false;
        };

        match scan.token_type {
            TOKEN_TYPE_TEXT => {
                if self.parsing_namespace != NAMESPACE_HTML {
                    return false;
                }
                let replacement = escape_html_text(plaintext);
                self.replace_range(scan.tag_start, scan.token_end, &replacement);
                true
            }
            TOKEN_TYPE_COMMENT => {
                if self.comment_type(scan) != COMMENT_TYPE_HTML {
                    return false;
                }
                if find_subslice(plaintext, b"-->").is_some()
                    || find_subslice(plaintext, b"--!>").is_some()
                {
                    return false;
                }
                let Some((start, end)) = self.comment_body_span(scan) else {
                    return false;
                };
                self.replace_range(start, end, plaintext);
                true
            }
            TOKEN_TYPE_TAG => self.set_atomic_modifiable_text(scan, plaintext),
            _ => false,
        }
    }

    fn set_atomic_modifiable_text(&mut self, scan: TagScan, plaintext: &[u8]) -> bool {
        if self.parsing_namespace != NAMESPACE_HTML || scan.is_closing || scan.token_end <= scan.tag_end {
            return false;
        }

        let Some((start, end)) = self.atomic_text_span(scan) else {
            return false;
        };

        let tag_name = &self.html[scan.name_start..scan.name_start + scan.name_len];
        let replacement = if eq_ignore_ascii_case(tag_name, b"SCRIPT") {
            let script_type = self.script_content_type(scan);
            match script_type {
                ScriptContentType::JavaScript | ScriptContentType::Json => {
                    escape_script_text(plaintext)
                }
                ScriptContentType::Other => {
                    if find_case_insensitive_script_tag(plaintext).is_some() {
                        return false;
                    }
                    plaintext.to_vec()
                }
            }
        } else if eq_ignore_ascii_case(tag_name, b"STYLE") {
            escape_rawtext_closer(plaintext, b"style", b"\\3c\\2f")
        } else if eq_ignore_ascii_case(tag_name, b"TEXTAREA") {
            let normalized = normalize_newlines(plaintext);
            let mut escaped = escape_rcdata_closer(&normalized, b"textarea");
            if matches!(escaped.first(), Some(b'\n')) {
                let mut with_extra_newline = Vec::with_capacity(escaped.len() + 1);
                with_extra_newline.push(b'\n');
                with_extra_newline.extend_from_slice(&escaped);
                escaped = with_extra_newline;
            }
            escaped
        } else if eq_ignore_ascii_case(tag_name, b"TITLE") {
            escape_rcdata_closer(plaintext, b"title")
        } else {
            return false;
        };

        self.replace_atomic_text_range(start, end, &replacement);
        true
    }

    fn atomic_text_span(&self, scan: TagScan) -> Option<(usize, usize)> {
        if scan.token_type != TOKEN_TYPE_TAG || scan.token_end <= scan.tag_end {
            return None;
        }

        let tag_name = &self.html[scan.name_start..scan.name_start + scan.name_len];
        if find_special_closer(&self.html[..scan.token_end], scan.tag_end, tag_name).is_some() {
            let inner = &self.html[scan.tag_end..scan.token_end];
            return find_last_subslice(inner, b"</")
                .map(|relative| (scan.tag_end, scan.tag_end + relative));
        }

        Some((
            scan.tag_end,
            unclosed_atomic_text_end(&self.html, scan.tag_end, scan.token_end, tag_name),
        ))
    }

    fn comment_body_span(&self, scan: TagScan) -> Option<(usize, usize)> {
        if scan.token_type != TOKEN_TYPE_COMMENT {
            return None;
        }

        let token = &self.html[scan.tag_start..scan.token_end];
        if !token.starts_with(b"<!--") {
            return None;
        }

        let body_start = scan.tag_start + 4;
        let mut body_end = if token.ends_with(b"--!>") {
            scan.token_end.saturating_sub(4)
        } else if token.ends_with(b"-->") {
            scan.token_end.saturating_sub(3)
        } else {
            scan.token_end.saturating_sub(1)
        };

        if body_end < body_start {
            body_end = body_start;
        }

        Some((body_start, body_end))
    }

    fn script_content_type(&self, scan: TagScan) -> ScriptContentType {
        let type_attr = self.find_attribute(scan, b"type");
        let language_attr = self.find_attribute(scan, b"language");

        if let Some(attribute) = type_attr {
            let type_string = match attribute.value {
                None => return ScriptContentType::JavaScript,
                Some((start, end)) => {
                    let decoded = decode_html_attribute(&self.html[start..end]);
                    let trimmed = trim_ascii_whitespace(&decoded);
                    if trimmed.is_empty() {
                        return ScriptContentType::JavaScript;
                    }
                    ascii_lowercase_vec(trimmed)
                }
            };

            return classify_script_type_string(&type_string);
        }

        let Some(attribute) = language_attr else {
            return ScriptContentType::JavaScript;
        };

        let language = match attribute.value {
            None => return ScriptContentType::JavaScript,
            Some((start, end)) => decode_html_attribute(&self.html[start..end]),
        };

        if language.is_empty() {
            return ScriptContentType::JavaScript;
        }

        let mut type_string = Vec::with_capacity(b"text/".len() + language.len());
        type_string.extend_from_slice(b"text/");
        type_string.extend(ascii_lowercase_vec(&language));
        classify_script_type_string(&type_string)
    }

    fn get_attribute(&mut self, wanted_name: &[u8]) -> AttributeValue {
        let Some(scan) = self.current else {
            return AttributeValue::Missing;
        };

        if scan.token_type != TOKEN_TYPE_TAG || scan.is_closing {
            return AttributeValue::Missing;
        }

        let Some(attribute) = self.find_attribute(scan, wanted_name) else {
            return AttributeValue::Missing;
        };

        let Some((value_start, value_end)) = attribute.value else {
            return AttributeValue::Boolean;
        };

        self.scratch = decode_html_attribute(&self.html[value_start..value_end]);
        AttributeValue::String
    }

    fn set_attribute(&mut self, name: &[u8], value: &[u8], value_kind: u8) -> bool {
        let Some(scan) = self.current else {
            return false;
        };

        if scan.token_type != TOKEN_TYPE_TAG || scan.is_closing || !is_valid_attribute_name(name) {
            return false;
        }

        if value_kind == 0 {
            return self.remove_attribute(name);
        }

        let comparable_name = ascii_lowercase_vec(name);
        let replacement = serialize_attribute(name, value, value_kind);

        if let Some(attribute) = self.find_attribute(scan, name) {
            self.replace_range(attribute.name_start, attribute.full_end, &replacement);
            return true;
        }

        let mut inserted = Vec::with_capacity(replacement.len() + 1);
        inserted.push(b' ');
        inserted.extend_from_slice(&replacement);

        let insertion_point = scan.name_start + scan.name_len;
        self.replace_range(insertion_point, insertion_point, &inserted);
        if !self
            .inserted_attributes
            .iter()
            .any(|inserted_name| inserted_name == &comparable_name)
        {
            self.inserted_attributes.push(comparable_name);
        }
        true
    }

    fn remove_attribute(&mut self, name: &[u8]) -> bool {
        let Some(scan) = self.current else {
            return false;
        };

        if scan.token_type != TOKEN_TYPE_TAG || scan.is_closing {
            return false;
        }

        let comparable_name = ascii_lowercase_vec(name);
        let remove_inserted_space = self
            .inserted_attributes
            .iter()
            .any(|inserted_name| inserted_name == &comparable_name);
        let mut removed = false;
        while let Some(attribute) = self.current.and_then(|current| self.find_attribute(current, name)) {
            let removal_start = if remove_inserted_space
                && attribute.name_start > 0
                && is_html_whitespace(self.html[attribute.name_start - 1])
            {
                attribute.name_start - 1
            } else {
                attribute.name_start
            };
            self.replace_range(removal_start, attribute.full_end, &[]);
            removed = true;
        }

        if removed {
            self.inserted_attributes
                .retain(|inserted_name| inserted_name != &comparable_name);
        }

        removed
    }

    fn add_class(&mut self, class_name: &[u8], quirks_mode: bool) -> bool {
        let Some(scan) = self.current else {
            return false;
        };

        if scan.token_type != TOKEN_TYPE_TAG || scan.is_closing {
            return false;
        }

        let comparable_class_name = comparable_class_bytes(class_name, quirks_mode);
        if self
            .current_raw_class_entries(quirks_mode)
            .iter()
            .any(|class| class.comparable.as_slice() == comparable_class_name.as_slice())
        {
            return true;
        }

        match self.get_attribute(b"class") {
            AttributeValue::String => {
                let mut value = self.scratch.clone();
                trim_html_whitespace_in_place(&mut value);
                if !value.is_empty() {
                    value.push(b' ');
                }
                value.extend_from_slice(class_name);
                self.set_attribute(b"class", &value, 2)
            }
            AttributeValue::Boolean | AttributeValue::Missing => {
                self.set_attribute(b"class", class_name, 2)
            }
        }
    }

    fn remove_class(&mut self, class_name: &[u8], quirks_mode: bool) -> bool {
        let Some(scan) = self.current else {
            return false;
        };

        if scan.token_type != TOKEN_TYPE_TAG || scan.is_closing {
            return false;
        }

        let comparable_class_name = comparable_class_bytes(class_name, quirks_mode);
        let entries = self.current_raw_class_entries(quirks_mode);
        let classes: Vec<Vec<u8>> = entries
            .iter()
            .filter(|class| class.comparable.as_slice() != comparable_class_name.as_slice())
            .map(|class| class.name.clone())
            .collect();

        if classes.len() == entries.len() {
            return true;
        }

        if classes.is_empty() {
            let _ = self.remove_attribute(b"class");
            return true;
        }

        let value = join_classes(&classes);
        self.set_attribute(b"class", &value, 2)
    }

    fn has_class(&mut self, class_name: &[u8], quirks_mode: bool) -> u8 {
        let Some(scan) = self.current else {
            return 0;
        };

        if scan.token_type != TOKEN_TYPE_TAG || scan.is_closing {
            return 0;
        }

        let comparable_class_name = comparable_class_bytes(class_name, quirks_mode);

        if self
            .current_public_class_entries(quirks_mode)
            .into_iter()
            .any(|class| class.comparable.as_slice() == comparable_class_name.as_slice())
        {
            2
        } else {
            1
        }
    }

    fn class_list(&mut self, quirks_mode: bool) -> bool {
        let Some(scan) = self.current else {
            return false;
        };

        if scan.token_type != TOKEN_TYPE_TAG || scan.is_closing {
            return false;
        }

        let classes = self.current_public_class_entries(quirks_mode);
        self.scratch.clear();
        for class in classes {
            if !self.scratch.is_empty() {
                self.scratch.push(0x1f);
            }
            self.scratch.extend_from_slice(if quirks_mode {
                &class.comparable
            } else {
                &class.name
            });
        }

        true
    }

    fn current_raw_class_entries(&mut self, quirks_mode: bool) -> Vec<ClassEntry> {
        let value = match self.get_attribute(b"class") {
            AttributeValue::String => self.scratch.clone(),
            AttributeValue::Boolean | AttributeValue::Missing => Vec::new(),
        };

        let mut classes = Vec::new();
        for class in value.split(|byte| is_html_whitespace(*byte)) {
            if class.is_empty() {
                continue;
            }

            let name = class.to_vec();
            let comparable = comparable_class_bytes(&name, quirks_mode);
            if classes
                .iter()
                .any(|seen: &ClassEntry| seen.comparable.as_slice() == comparable.as_slice())
            {
                continue;
            }
            classes.push(ClassEntry { name, comparable });
        }

        classes
    }

    fn current_public_class_entries(&mut self, quirks_mode: bool) -> Vec<ClassEntry> {
        let value = match self.get_attribute(b"class") {
            AttributeValue::String => self.scratch.clone(),
            AttributeValue::Boolean | AttributeValue::Missing => Vec::new(),
        };

        let mut classes = Vec::new();
        for class in value.split(|byte| is_html_whitespace(*byte)) {
            if class.is_empty() {
                continue;
            }

            let name = normalize_class_bytes(class);
            let comparable = comparable_class_bytes(&name, quirks_mode);
            if classes
                .iter()
                .any(|seen: &ClassEntry| seen.comparable.as_slice() == comparable.as_slice())
            {
                continue;
            }
            classes.push(ClassEntry { name, comparable });
        }

        classes
    }

    fn get_attribute_names_with_prefix(&mut self, prefix: &[u8]) -> bool {
        let Some(scan) = self.current else {
            return false;
        };

        if scan.token_type != TOKEN_TYPE_TAG || scan.is_closing {
            return false;
        }

        self.scratch.clear();
        let mut at = scan.name_start + scan.name_len;
        let mut end = scan.tag_end.saturating_sub(1);
        let comparable_prefix = comparable_attribute_name(prefix);
        let mut seen_attribute_names: Vec<Vec<u8>> = Vec::new();

        if tag_ends_with_syntactic_self_closing_flag(
            &self.html,
            scan.name_start + scan.name_len,
            scan.tag_end,
        ) {
            end = end.saturating_sub(1);
        }

        while at < end {
            while at < end && (is_html_whitespace(self.html[at]) || self.html[at] == b'/') {
                at += 1;
            }

            if at >= end {
                break;
            }

            let name_start = at;
            while at < end && !is_attribute_name_delimiter(self.html[at]) {
                at += 1;
            }

            if name_start == at {
                at += 1;
                continue;
            }

            let name_end = at;
            let comparable_name = comparable_attribute_name(&self.html[name_start..name_end]);
            if comparable_name.starts_with(&comparable_prefix)
                && !seen_attribute_names
                    .iter()
                    .any(|seen| seen.as_slice() == comparable_name.as_slice())
            {
                if !self.scratch.is_empty() {
                    self.scratch.push(0);
                }
                self.scratch.extend_from_slice(&comparable_name);
                seen_attribute_names.push(comparable_name);
            }

            while at < end && is_html_whitespace(self.html[at]) {
                at += 1;
            }

            if at < end && self.html[at] == b'=' {
                at += 1;
                while at < end && is_html_whitespace(self.html[at]) {
                    at += 1;
                }

                if at < end && (self.html[at] == b'\'' || self.html[at] == b'"') {
                    let quote = self.html[at];
                    at += 1;
                    while at < end && self.html[at] != quote {
                        at += 1;
                    }
                    if at < end {
                        at += 1;
                    }
                } else {
                    while at < end && !is_html_whitespace(self.html[at]) {
                        at += 1;
                    }
                }
            }
        }

        true
    }

    fn find_attribute(&self, scan: TagScan, wanted_name: &[u8]) -> Option<AttributeSpan> {
        let mut at = scan.name_start + scan.name_len;
        let mut end = scan.tag_end.saturating_sub(1);
        let comparable_wanted_name = comparable_attribute_name(wanted_name);

        if tag_ends_with_syntactic_self_closing_flag(
            &self.html,
            scan.name_start + scan.name_len,
            scan.tag_end,
        ) {
            end = end.saturating_sub(1);
        }

        while at < end {
            while at < end && (is_html_whitespace(self.html[at]) || self.html[at] == b'/') {
                at += 1;
            }

            if at >= end {
                break;
            }

            let name_start = at;
            while at < end && !is_attribute_name_delimiter(self.html[at]) {
                at += 1;
            }

            if name_start == at {
                at += 1;
                continue;
            }

            let name_end = at;
            let mut full_end = name_end;
            while at < end && is_html_whitespace(self.html[at]) {
                at += 1;
            }

            let mut value = None;
            if at < end && self.html[at] == b'=' {
                at += 1;
                while at < end && is_html_whitespace(self.html[at]) {
                    at += 1;
                }

                if at < end && (self.html[at] == b'\'' || self.html[at] == b'"') {
                    let quote = self.html[at];
                    at += 1;
                    let value_start = at;
                    while at < end && self.html[at] != quote {
                        at += 1;
                    }
                    value = Some((value_start, at));
                    if at < end {
                        at += 1;
                    }
                } else {
                    let value_start = at;
                    while at < end && !is_html_whitespace(self.html[at]) {
                        at += 1;
                    }
                    value = Some((value_start, at));
                }
                full_end = at;
            }

            if comparable_attribute_name(&self.html[name_start..name_end]) == comparable_wanted_name {
                return Some(AttributeSpan {
                    name_start,
                    full_end,
                    value,
                });
            }
        }

        None
    }

    fn replace_range(&mut self, start: usize, end: usize, replacement: &[u8]) {
        self.replace_range_internal(start, end, replacement, true);
    }

    fn replace_range_preserving_cursor_at_inserted_start(
        &mut self,
        start: usize,
        end: usize,
        replacement: &[u8],
    ) {
        self.replace_range_internal(start, end, replacement, false);
    }

    fn replace_atomic_text_range(&mut self, start: usize, end: usize, replacement: &[u8]) {
        let old_len = end - start;
        let new_len = replacement.len();
        self.html.splice(start..end, replacement.iter().copied());

        let delta = new_len as isize - old_len as isize;
        if delta == 0 {
            return;
        }

        if let Some(scan) = self.current.as_mut() {
            if should_shift_point(scan.token_end, end, old_len, true) {
                scan.token_end = scan.token_end.saturating_add_signed(delta);
            }
            scan.has_self_closing_flag = scan.tag_end >= 2 && self.html[scan.tag_end - 2] == b'/';
        }

        if should_shift_point(self.offset, end, old_len, true) {
            self.offset = self.offset.saturating_add_signed(delta);
        }
    }

    fn replace_range_internal(
        &mut self,
        start: usize,
        end: usize,
        replacement: &[u8],
        shift_points_at_zero_width_end: bool,
    ) {
        let old_len = end - start;
        let new_len = replacement.len();
        self.html.splice(start..end, replacement.iter().copied());

        let delta = new_len as isize - old_len as isize;
        if delta == 0 {
            return;
        }

        if let Some(scan) = self.current.as_mut() {
            if should_shift_point(scan.tag_end, end, old_len, shift_points_at_zero_width_end) {
                scan.tag_end = scan.tag_end.saturating_add_signed(delta);
            }
            if should_shift_point(scan.token_end, end, old_len, shift_points_at_zero_width_end) {
                scan.token_end = scan.token_end.saturating_add_signed(delta);
            }
            scan.has_self_closing_flag = scan.tag_end >= 2 && self.html[scan.tag_end - 2] == b'/';
        }

        if should_shift_point(self.offset, end, old_len, shift_points_at_zero_width_end) {
            self.offset = self.offset.saturating_add_signed(delta);
        }
    }
}

fn should_shift_point(
    point: usize,
    edit_end: usize,
    old_len: usize,
    shift_points_at_zero_width_end: bool,
) -> bool {
    point > edit_end || (point == edit_end && (old_len > 0 || shift_points_at_zero_width_end))
}

fn trim_html_whitespace_in_place(value: &mut Vec<u8>) {
    let start = value
        .iter()
        .position(|&byte| !is_html_whitespace(byte))
        .unwrap_or(value.len());
    let end = value
        .iter()
        .rposition(|&byte| !is_html_whitespace(byte))
        .map(|index| index + 1)
        .unwrap_or(start);

    if start > 0 || end < value.len() {
        value.copy_within(start..end, 0);
        value.truncate(end - start);
    }
}

#[derive(Clone, Copy)]
enum NullTransform {
    Remove,
    Replace,
}

#[derive(Clone, Copy, Eq, PartialEq)]
enum DecodeContext {
    Data,
    Attribute,
}

#[derive(Clone, Copy)]
enum CharacterReference {
    Scalar(char),
    Text(&'static str),
}

impl CharacterReference {
    fn append_to(self, output: &mut Vec<u8>) {
        match self {
            CharacterReference::Scalar(value) => {
                let mut buffer = [0; 4];
                output.extend_from_slice(value.encode_utf8(&mut buffer).as_bytes());
            }
            CharacterReference::Text(value) => output.extend_from_slice(value.as_bytes()),
        }
    }

    fn is_line_feed(self) -> bool {
        matches!(self, CharacterReference::Scalar('\n'))
    }

    fn is_null(self) -> bool {
        matches!(self, CharacterReference::Scalar('\0'))
    }

    fn is_html_whitespace(self) -> bool {
        matches!(
            self,
            CharacterReference::Scalar(' ')
                | CharacterReference::Scalar('\t')
                | CharacterReference::Scalar('\n')
                | CharacterReference::Scalar('\u{000C}')
                | CharacterReference::Scalar('\r')
        )
    }
}

#[derive(Clone, Copy)]
enum ScriptContentType {
    JavaScript,
    Json,
    Other,
}

fn classify_script_type_string(type_string: &[u8]) -> ScriptContentType {
    match type_string {
        b"application/ecmascript"
        | b"application/javascript"
        | b"application/x-ecmascript"
        | b"application/x-javascript"
        | b"text/ecmascript"
        | b"text/javascript"
        | b"text/javascript1.0"
        | b"text/javascript1.1"
        | b"text/javascript1.2"
        | b"text/javascript1.3"
        | b"text/javascript1.4"
        | b"text/javascript1.5"
        | b"text/jscript"
        | b"text/livescript"
        | b"text/x-ecmascript"
        | b"text/x-javascript"
        | b"module" => ScriptContentType::JavaScript,
        b"importmap" | b"speculationrules" | b"application/json" | b"text/json" => {
            ScriptContentType::Json
        }
        _ => ScriptContentType::Other,
    }
}

fn transform_text(input: &[u8], decode_entities: bool, null_transform: NullTransform) -> Vec<u8> {
    let mut output = Vec::with_capacity(input.len());
    let mut at = 0;

    while at < input.len() {
        if input[at] == b'\r' {
            output.push(b'\n');
            at += if input.get(at + 1) == Some(&b'\n') { 2 } else { 1 };
            continue;
        }

        if input[at] == 0 {
            match null_transform {
                NullTransform::Remove => {}
                NullTransform::Replace => output.extend_from_slice("\u{FFFD}".as_bytes()),
            }
            at += 1;
            continue;
        }

        if decode_entities && input[at] == b'&' {
            if let Some((decoded, consumed)) =
                decode_character_reference(DecodeContext::Data, &input[at..])
            {
                if decoded.is_null() {
                    match null_transform {
                        NullTransform::Remove => {}
                        NullTransform::Replace => output.extend_from_slice("\u{FFFD}".as_bytes()),
                    }
                } else {
                    decoded.append_to(&mut output);
                }
                at += consumed;
                continue;
            }
        }

        output.push(input[at]);
        at += 1;
    }

    output
}

fn strip_initial_newline(input: &[u8]) -> &[u8] {
    if input.starts_with(b"\r\n") {
        return &input[2..];
    }

    if input.starts_with(b"\r") {
        return &input[1..];
    }

    if input.starts_with(b"\n") {
        return &input[1..];
    }

    if let Some((decoded, consumed)) = decode_character_reference(DecodeContext::Data, input) {
        if !decoded.is_line_feed() {
            return input;
        }

        return &input[consumed..];
    }

    input
}

fn normalize_newlines(input: &[u8]) -> Vec<u8> {
    let mut normalized = Vec::with_capacity(input.len());
    let mut at = 0;

    while at < input.len() {
        if input[at] == b'\r' {
            normalized.push(b'\n');
            at += if input.get(at + 1) == Some(&b'\n') { 2 } else { 1 };
            continue;
        }

        normalized.push(input[at]);
        at += 1;
    }

    normalized
}

fn pi_target_span(html: &[u8], scan: TagScan) -> Option<(usize, usize)> {
    if scan.token_type != TOKEN_TYPE_COMMENT {
        return None;
    }

    if scan.tag_start + 3 > scan.token_end || !html[scan.tag_start..scan.token_end].starts_with(b"<?") {
        return None;
    }

    let target_start = scan.tag_start + 2;
    let mut target_end = target_start;
    while target_end < scan.token_end && is_pi_target_char(html[target_end]) {
        target_end += 1;
    }

    if target_end == target_start {
        return None;
    }

    if target_end >= scan.token_end || !is_html_whitespace(html[target_end]) {
        return None;
    }

    Some((target_start, target_end))
}

fn is_pi_target_char(byte: u8) -> bool {
    byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_' | b':' | b'.')
}

fn escape_html_text(input: &[u8]) -> Vec<u8> {
    let mut output = Vec::with_capacity(input.len());
    for &byte in input {
        match byte {
            b'<' => output.extend_from_slice(b"&lt;"),
            b'>' => output.extend_from_slice(b"&gt;"),
            b'&' => output.extend_from_slice(b"&amp;"),
            b'"' => output.extend_from_slice(b"&quot;"),
            b'\'' => output.extend_from_slice(b"&apos;"),
            _ => output.push(byte),
        }
    }
    output
}

fn escape_script_text(input: &[u8]) -> Vec<u8> {
    let mut output = Vec::with_capacity(input.len());
    let mut at = 0;

    while at < input.len() {
        if let Some((script_start, escape_at)) = script_tag_match_at(input, at) {
            output.extend_from_slice(&input[at..escape_at]);
            let escaped = if input[escape_at].is_ascii_uppercase() {
                b"\\u0053"
            } else {
                b"\\u0073"
            };
            output.extend_from_slice(escaped);
            at = escape_at + 1;
            if script_start == at {
                at += 1;
            }
            continue;
        }

        output.push(input[at]);
        at += 1;
    }

    output
}

fn find_case_insensitive_script_tag(input: &[u8]) -> Option<usize> {
    let mut at = 0;
    while at < input.len() {
        if script_tag_match_at(input, at).is_some() {
            return Some(at);
        }
        at += 1;
    }
    None
}

fn script_tag_match_at(input: &[u8], at: usize) -> Option<(usize, usize)> {
    if at >= input.len() || input[at] != b'<' {
        return None;
    }

    let (name_start, escape_at) = if at + 1 < input.len() && input[at + 1] == b'/' {
        (at + 2, at + 2)
    } else {
        (at + 1, at + 1)
    };

    let name_end = name_start + b"script".len();
    if name_end > input.len() || !eq_ignore_ascii_case(&input[name_start..name_end], b"script") {
        return None;
    }

    if name_end < input.len() && !is_tag_name_delimiter(input[name_end]) {
        return None;
    }

    Some((at, escape_at))
}

fn escape_rawtext_closer(input: &[u8], tag_name: &[u8], prefix: &[u8]) -> Vec<u8> {
    let mut output = Vec::with_capacity(input.len());
    let mut at = 0;

    while at < input.len() {
        if starts_with_rawtext_closer(input, at, tag_name) {
            output.extend_from_slice(prefix);
            output.extend_from_slice(&input[at + 2..at + 2 + tag_name.len()]);
            at += 2 + tag_name.len();
            continue;
        }

        output.push(input[at]);
        at += 1;
    }

    output
}

fn escape_rcdata_closer(input: &[u8], tag_name: &[u8]) -> Vec<u8> {
    escape_rawtext_closer(input, tag_name, b"&lt;/")
}

fn starts_with_rawtext_closer(input: &[u8], at: usize, tag_name: &[u8]) -> bool {
    if at + 2 + tag_name.len() > input.len() || input[at] != b'<' || input[at + 1] != b'/' {
        return false;
    }

    let name_start = at + 2;
    let name_end = name_start + tag_name.len();
    if !eq_ignore_ascii_case(&input[name_start..name_end], tag_name) {
        return false;
    }

    name_end == input.len() || is_tag_name_delimiter(input[name_end])
}

fn trim_ascii_whitespace(value: &[u8]) -> &[u8] {
    let start = value
        .iter()
        .position(|&byte| !is_html_whitespace(byte))
        .unwrap_or(value.len());
    let end = value
        .iter()
        .rposition(|&byte| !is_html_whitespace(byte))
        .map(|index| index + 1)
        .unwrap_or(start);
    &value[start..end]
}

fn scan_next_tag(html: &[u8], offset: usize) -> Option<TagScan> {
    let mut at = offset.min(html.len());

    loop {
        match scan_next_token(html, at) {
            ScanResult::Token(scan) if scan.token_type == TOKEN_TYPE_TAG => return Some(scan),
            ScanResult::Token(scan) => at = scan.token_end,
            ScanResult::Incomplete | ScanResult::None => return None,
        }
    }
}

enum ScanResult {
    Token(TagScan),
    Incomplete,
    None,
}

fn scan_next_token(html: &[u8], offset: usize) -> ScanResult {
    scan_next_token_in_namespace(html, offset, NAMESPACE_HTML)
}

fn scan_next_token_in_namespace(html: &[u8], offset: usize, namespace: u8) -> ScanResult {
    let len = html.len();
    let at = offset.min(len);

    if at >= len {
        return ScanResult::None;
    }

    let Some(tag_start) = find_next_token_start(html, at) else {
        return ScanResult::Token(text_scan(at, len));
    };

    if tag_start > at {
        return ScanResult::Token(text_scan(at, tag_start));
    }

    if tag_start + 1 >= len {
        return ScanResult::Token(text_scan(tag_start, len));
    }

    if starts_with_ignore_ascii_case(&html[tag_start..], b"<!--") {
        return scan_comment(html, tag_start);
    }

    if starts_with_ignore_ascii_case(&html[tag_start..], b"<![CDATA[") {
        return scan_cdata(html, tag_start, namespace);
    }

    if starts_with_ignore_ascii_case(&html[tag_start..], b"<!DOCTYPE") {
        return scan_markup_declaration(html, tag_start, TOKEN_TYPE_DOCTYPE);
    }

    if tag_start + 1 < len && html[tag_start + 1] == b'!' {
        return scan_markup_declaration(html, tag_start, TOKEN_TYPE_COMMENT);
    }

    if html[tag_start + 1] == b'?' {
        return scan_markup_declaration(html, tag_start, TOKEN_TYPE_COMMENT);
    }

    let mut name_start = tag_start + 1;
    let is_closing = html[name_start] == b'/';
    if is_closing {
        name_start += 1;
    }

    if name_start >= len {
        return if is_closing {
            ScanResult::Token(text_scan(tag_start, len))
        } else {
            ScanResult::Incomplete
        };
    }

    if is_closing && html[name_start] == b'>' {
        return ScanResult::Token(TagScan {
            tag_start,
            tag_end: name_start + 1,
            name_start,
            name_len: 0,
            is_closing: true,
            has_self_closing_flag: false,
            token_end: name_start + 1,
            token_type: TOKEN_TYPE_PRESUMPTUOUS_TAG,
        });
    }

    if !html[name_start].is_ascii_alphabetic() {
        if is_closing {
            return scan_markup_declaration(html, tag_start, TOKEN_TYPE_FUNKY_COMMENT);
        }

        let text_end = html[tag_start + 1..]
            .iter()
            .position(|&byte| byte == b'<')
            .map(|relative| tag_start + 1 + relative)
            .unwrap_or(len);
        return ScanResult::Token(text_scan(tag_start, text_end));
    }

    let mut name_end = name_start + 1;
    while name_end < len && !is_tag_name_delimiter(html[name_end]) {
        name_end += 1;
    }

    let Some(tag_end) = find_tag_end(html, name_end) else {
        return ScanResult::Incomplete;
    };

    let mut scan = TagScan {
        tag_start,
        tag_end,
        name_start,
        name_len: name_end - name_start,
        is_closing,
        has_self_closing_flag: tag_end >= 2 && html[tag_end - 2] == b'/',
        token_end: tag_end,
        token_type: TOKEN_TYPE_TAG,
    };

    if namespace == NAMESPACE_HTML
        && !is_closing
        && is_special_atomic_tag(&html[name_start..name_end])
    {
        let tag_name = &html[name_start..name_end];
        if let Some(closer_end) = find_special_closer(html, tag_end, tag_name) {
            scan.token_end = closer_end;
        } else if should_consume_unclosed_atomic_tag_at_eof(tag_name) {
            scan.token_end = len;
        } else {
            return ScanResult::Incomplete;
        }
    }

    ScanResult::Token(scan)
}

fn tag_ends_with_syntactic_self_closing_flag(
    html: &[u8],
    name_end: usize,
    tag_end: usize,
) -> bool {
    if tag_end < 2 || html.get(tag_end - 2) != Some(&b'/') {
        return false;
    }

    let mut at = name_end;
    let end = tag_end - 1;

    while at < end {
        while at < end && is_html_whitespace(html[at]) {
            at += 1;
        }

        if at >= end {
            break;
        }

        if html[at] == b'/' {
            return at == tag_end - 2;
        }

        let name_start = at;
        while at < end && !is_attribute_name_delimiter(html[at]) {
            at += 1;
        }

        if name_start == at {
            at += 1;
            continue;
        }

        while at < end && is_html_whitespace(html[at]) {
            at += 1;
        }

        if at < end && html[at] == b'=' {
            at += 1;
            while at < end && is_html_whitespace(html[at]) {
                at += 1;
            }

            if at < end && (html[at] == b'\'' || html[at] == b'"') {
                let quote = html[at];
                at += 1;
                while at < end && html[at] != quote {
                    at += 1;
                }
                if at < end {
                    at += 1;
                }
            } else {
                while at < end && !is_html_whitespace(html[at]) {
                    at += 1;
                }
            }
        }
    }

    false
}

fn find_next_token_start(html: &[u8], offset: usize) -> Option<usize> {
    let mut at = offset;

    while at < html.len() {
        let relative = html[at..].iter().position(|&byte| byte == b'<')?;
        let tag_start = at + relative;

        if tag_start + 1 >= html.len() {
            return Some(tag_start);
        }

        let next = html[tag_start + 1];
        if next == b'!' || next == b'?' || next == b'/' || next.is_ascii_alphabetic() {
            return Some(tag_start);
        }

        at = tag_start + 1;
    }

    None
}

fn text_scan(start: usize, end: usize) -> TagScan {
    TagScan {
        tag_start: start,
        tag_end: end,
        name_start: start,
        name_len: 0,
        is_closing: false,
        has_self_closing_flag: false,
        token_end: end,
        token_type: TOKEN_TYPE_TEXT,
    }
}

fn scan_comment(html: &[u8], tag_start: usize) -> ScanResult {
    if tag_start + 4 < html.len() && html[tag_start..].starts_with(b"<!-->") {
        return ScanResult::Token(non_tag_scan(tag_start, tag_start + 5, TOKEN_TYPE_COMMENT));
    }

    if tag_start + 5 < html.len() && html[tag_start..].starts_with(b"<!--->") {
        return ScanResult::Token(non_tag_scan(tag_start, tag_start + 6, TOKEN_TYPE_COMMENT));
    }

    let Some(token_end) = find_comment_end(html, tag_start + 4) else {
        return ScanResult::Incomplete;
    };

    ScanResult::Token(non_tag_scan(tag_start, token_end, TOKEN_TYPE_COMMENT))
}

fn find_comment_end(html: &[u8], offset: usize) -> Option<usize> {
    let mut at = offset;

    while at + 2 < html.len() {
        let relative = find_subslice(&html[at..], b"--")?;
        let dash_start = at + relative;
        let after_dashes = dash_start + 2;

        if after_dashes < html.len() && html[after_dashes] == b'>' {
            return Some(after_dashes + 1);
        }

        if after_dashes + 1 < html.len()
            && html[after_dashes] == b'!'
            && html[after_dashes + 1] == b'>'
        {
            return Some(after_dashes + 2);
        }

        at = dash_start + 1;
    }

    None
}

fn scan_cdata(html: &[u8], tag_start: usize, namespace: u8) -> ScanResult {
    if namespace == NAMESPACE_HTML {
        return scan_markup_declaration(html, tag_start, TOKEN_TYPE_COMMENT);
    }

    let Some(relative_end) = find_subslice(&html[tag_start + 9..], b"]]>") else {
        return ScanResult::Token(non_tag_scan(tag_start, html.len(), TOKEN_TYPE_CDATA));
    };

    let token_end = tag_start + 9 + relative_end + 3;
    ScanResult::Token(non_tag_scan(tag_start, token_end, TOKEN_TYPE_CDATA))
}

fn scan_markup_declaration(html: &[u8], tag_start: usize, token_type: u8) -> ScanResult {
    let Some(relative_end) = html[tag_start + 2..].iter().position(|&byte| byte == b'>') else {
        return match token_type {
            TOKEN_TYPE_COMMENT | TOKEN_TYPE_FUNKY_COMMENT => {
                ScanResult::Token(non_tag_scan(tag_start, html.len(), token_type))
            }
            _ => ScanResult::Incomplete,
        };
    };

    let token_end = tag_start + 2 + relative_end + 1;
    ScanResult::Token(non_tag_scan(tag_start, token_end, token_type))
}

fn non_tag_scan(start: usize, end: usize, token_type: u8) -> TagScan {
    TagScan {
        tag_start: start,
        tag_end: end,
        name_start: start,
        name_len: 0,
        is_closing: false,
        has_self_closing_flag: false,
        token_end: end,
        token_type,
    }
}

fn is_special_atomic_tag(tag_name: &[u8]) -> bool {
    matches_ignore_ascii_case(
        tag_name,
        &[
            &b"IFRAME"[..],
            &b"NOEMBED"[..],
            &b"NOFRAMES"[..],
            &b"SCRIPT"[..],
            &b"STYLE"[..],
            &b"TEXTAREA"[..],
            &b"TITLE"[..],
            &b"XMP"[..],
        ],
    )
}

fn should_consume_unclosed_atomic_tag_at_eof(tag_name: &[u8]) -> bool {
    is_special_atomic_tag(tag_name)
}

fn find_special_closer(html: &[u8], offset: usize, tag_name: &[u8]) -> Option<usize> {
    if eq_ignore_ascii_case(tag_name, b"SCRIPT") {
        return find_script_closer(html, offset);
    }

    let mut at = offset;

    while at + 3 + tag_name.len() <= html.len() {
        let relative = find_subslice(&html[at..], b"</")?;
        let closer_start = at + relative;
        let name_start = closer_start + 2;
        let name_end = name_start + tag_name.len();

        if name_end <= html.len()
            && eq_ignore_ascii_case(&html[name_start..name_end], tag_name)
            && (name_end == html.len() || is_tag_name_delimiter(html[name_end]))
        {
            return find_tag_end(html, name_end);
        }

        at = closer_start + 2;
    }

    None
}

fn unclosed_atomic_text_end(
    html: &[u8],
    text_start: usize,
    token_end: usize,
    tag_name: &[u8],
) -> usize {
    if eq_ignore_ascii_case(tag_name, b"SCRIPT") {
        return unclosed_script_text_end(html, text_start, token_end);
    }

    let mut at = text_start;

    while at + 2 + tag_name.len() <= token_end {
        let Some(relative) = find_subslice(&html[at..token_end], b"</") else {
            return token_end;
        };
        let closer_start = at + relative;
        let name_start = closer_start + 2;
        let name_end = name_start + tag_name.len();

        if name_end < token_end
            && eq_ignore_ascii_case(&html[name_start..name_end], tag_name)
            && is_tag_name_delimiter(html[name_end])
            && find_tag_end(html, name_end).is_none()
        {
            return closer_start;
        }

        at = closer_start + 2;
    }

    token_end
}

fn unclosed_script_text_end(html: &[u8], text_start: usize, token_end: usize) -> usize {
    let mut at = text_start;
    let mut escaped = false;
    let mut double_escaped = false;

    while at < token_end {
        if html[at..token_end].starts_with(b"<!-->") {
            at += 5;
            continue;
        }

        if html[at..token_end].starts_with(b"<!--") {
            escaped = true;
            double_escaped = false;
            at += 4;
            continue;
        }

        if (escaped || double_escaped) && html[at..token_end].starts_with(b"-->") {
            escaped = false;
            double_escaped = false;
            at += 3;
            continue;
        }

        if starts_with_ignore_ascii_case(&html[at..token_end], b"</script") {
            let name_end = at + b"</script".len();
            if name_end == token_end || is_tag_name_delimiter(html[name_end]) {
                if double_escaped {
                    double_escaped = false;
                    escaped = true;
                    at = name_end;
                    continue;
                }

                if name_end < token_end && find_tag_end(html, name_end).is_none() {
                    return at;
                }
            }
        }

        if escaped && starts_with_ignore_ascii_case(&html[at..token_end], b"<script") {
            let name_end = at + b"<script".len();
            if name_end == token_end || is_tag_name_delimiter(html[name_end]) {
                double_escaped = true;
                at = name_end;
                continue;
            }
        }

        at += 1;
    }

    token_end
}

fn find_script_closer(html: &[u8], offset: usize) -> Option<usize> {
    let mut at = offset;
    let mut escaped = false;
    let mut double_escaped = false;

    while at < html.len() {
        if html[at..].starts_with(b"<!-->") {
            at += 5;
            continue;
        }

        if html[at..].starts_with(b"<!--") {
            escaped = true;
            double_escaped = false;
            at += 4;
            continue;
        }

        if (escaped || double_escaped) && html[at..].starts_with(b"-->") {
            escaped = false;
            double_escaped = false;
            at += 3;
            continue;
        }

        if starts_with_ignore_ascii_case(&html[at..], b"</script") {
            let name_end = at + b"</script".len();
            if name_end == html.len() || is_tag_name_delimiter(html[name_end]) {
                if double_escaped {
                    double_escaped = false;
                    escaped = true;
                    at = name_end;
                    continue;
                }

                return find_tag_end(html, name_end);
            }
        }

        if escaped && starts_with_ignore_ascii_case(&html[at..], b"<script") {
            let name_end = at + b"<script".len();
            if name_end == html.len() || is_tag_name_delimiter(html[name_end]) {
                double_escaped = true;
                at = name_end;
                continue;
            }
        }

        at += 1;
    }

    None
}

fn find_subslice(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    if needle.is_empty() {
        return Some(0);
    }

    haystack
        .windows(needle.len())
        .position(|candidate| candidate == needle)
}

fn find_last_subslice(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    if needle.is_empty() {
        return Some(haystack.len());
    }

    haystack
        .windows(needle.len())
        .rposition(|candidate| candidate == needle)
}

fn matches_ignore_ascii_case(value: &[u8], candidates: &[&[u8]]) -> bool {
    candidates
        .iter()
        .any(|candidate| value.len() == candidate.len() && eq_ignore_ascii_case(value, candidate))
}

fn is_tag_name_delimiter(byte: u8) -> bool {
    matches!(byte, b' ' | b'\t' | b'\n' | b'\x0c' | b'\r' | b'/' | b'>')
}

fn is_attribute_name_delimiter(byte: u8) -> bool {
    matches!(byte, b' ' | b'\t' | b'\n' | b'\x0c' | b'\r' | b'/' | b'>' | b'=')
}

fn is_html_whitespace(byte: u8) -> bool {
    matches!(byte, b' ' | b'\t' | b'\n' | b'\x0c' | b'\r')
}

fn is_valid_attribute_name(name: &[u8]) -> bool {
    if name.is_empty() {
        return false;
    }

    name.iter().all(|&byte| {
        byte > 0x1f
            && !matches!(
                byte,
                b' ' | b'\t' | b'\n' | b'\x0c' | b'\r' | b'"' | b'\'' | b'>' | b'&' | b'<' | b'/' | b'='
            )
    })
}

fn serialize_attribute(name: &[u8], value: &[u8], value_kind: u8) -> Vec<u8> {
    let mut output = Vec::new();
    output.extend_from_slice(name);

    if value_kind == 1 {
        return output;
    }

    output.extend_from_slice(b"=\"");
    output.extend_from_slice(&encode_html_attribute(value));
    output.push(b'"');
    output
}

fn encode_html_attribute(input: &[u8]) -> Vec<u8> {
    let mut output = Vec::with_capacity(input.len());

    for &byte in input {
        match byte {
            b'&' => output.extend_from_slice(b"&amp;"),
            b'"' => output.extend_from_slice(b"&quot;"),
            b'\'' => output.extend_from_slice(b"&apos;"),
            b'<' => output.extend_from_slice(b"&lt;"),
            b'>' => output.extend_from_slice(b"&gt;"),
            _ => output.push(byte),
        }
    }

    output
}

fn join_classes(classes: &[Vec<u8>]) -> Vec<u8> {
    let mut output = Vec::new();

    for class in classes {
        if !output.is_empty() {
            output.push(b' ');
        }
        output.extend_from_slice(class);
    }

    output
}

fn normalize_class_bytes(class_name: &[u8]) -> Vec<u8> {
    let mut output = Vec::with_capacity(class_name.len());

    for &byte in class_name {
        if byte == 0 {
            output.extend_from_slice("\u{fffd}".as_bytes());
        } else {
            output.push(byte);
        }
    }

    output
}

fn comparable_class_bytes(class_name: &[u8], quirks_mode: bool) -> Vec<u8> {
    if quirks_mode {
        ascii_lowercase_vec(class_name)
    } else {
        class_name.to_vec()
    }
}

fn comparable_attribute_name(name: &[u8]) -> Vec<u8> {
    let mut output = Vec::with_capacity(name.len());

    for &byte in name {
        if byte == 0 {
            output.extend_from_slice("\u{fffd}".as_bytes());
        } else {
            output.push(byte.to_ascii_lowercase());
        }
    }

    output
}

fn ascii_lowercase_vec(value: &[u8]) -> Vec<u8> {
    value.iter().map(u8::to_ascii_lowercase).collect()
}

fn find_tag_end(html: &[u8], offset: usize) -> Option<usize> {
    let mut at = offset;
    let mut quote = None;
    let mut after_equals = false;

    while at < html.len() {
        let byte = html[at];

        match quote {
            Some(quote_byte) if byte == quote_byte => quote = None,
            Some(_) => {}
            None if after_equals && (byte == b'\'' || byte == b'"') => {
                quote = Some(byte);
                after_equals = false;
            }
            None if byte == b'=' => after_equals = true,
            None if is_html_whitespace(byte) => {}
            None if byte == b'/' => {}
            None if byte == b'>' => return Some(at + 1),
            None => after_equals = false,
        }

        at += 1;
    }

    None
}

fn eq_ignore_ascii_case(left: &[u8], right: &[u8]) -> bool {
    left.len() == right.len()
        && left.iter()
            .zip(right.iter())
            .all(|(&left, &right)| left.eq_ignore_ascii_case(&right))
}

fn starts_with_ignore_ascii_case(value: &[u8], prefix: &[u8]) -> bool {
    value.len() >= prefix.len() && eq_ignore_ascii_case(&value[..prefix.len()], prefix)
}

fn decode_html_attribute(input: &[u8]) -> Vec<u8> {
    let mut output = Vec::with_capacity(input.len());
    let mut at = 0;

    while at < input.len() {
        if input[at] != b'&' {
            output.push(input[at]);
            at += 1;
            continue;
        }

        let Some((decoded, consumed)) =
            decode_character_reference(DecodeContext::Attribute, &input[at..])
        else {
            output.push(input[at]);
            at += 1;
            continue;
        };

        decoded.append_to(&mut output);
        at += consumed;
    }

    output
}

fn decode_html_text(context: DecodeContext, input: &[u8]) -> Vec<u8> {
    let mut output = Vec::with_capacity(input.len());
    let mut at = 0;

    while at < input.len() {
        if input[at] != b'&' {
            output.push(input[at]);
            at += 1;
            continue;
        }

        let Some((decoded, consumed)) = decode_character_reference(context, &input[at..]) else {
            output.push(input[at]);
            at += 1;
            continue;
        };

        decoded.append_to(&mut output);
        at += consumed;
    }

    output
}

fn decode_context_from_byte(context: u8) -> DecodeContext {
    if context == 1 {
        DecodeContext::Attribute
    } else {
        DecodeContext::Data
    }
}

unsafe fn write_output_buffer(
    output: &[u8],
    out_ptr: *mut u8,
    out_capacity: usize,
    out_len: *mut usize,
) -> bool {
    if output.len() > out_capacity {
        return false;
    }

    if !output.is_empty() {
        ptr::copy_nonoverlapping(output.as_ptr(), out_ptr, output.len());
    }
    ptr::write(out_len, output.len());
    true
}

fn attribute_starts_with(
    haystack: &[u8],
    search_text: &[u8],
    ascii_case_insensitive: bool,
) -> bool {
    let mut search_at = 0;
    let mut haystack_at = 0;

    while search_at < search_text.len() && haystack_at < haystack.len() {
        if haystack[haystack_at] == b'&' {
            if let Some((decoded, consumed)) =
                decode_character_reference(DecodeContext::Attribute, &haystack[haystack_at..])
            {
                let mut decoded_bytes = Vec::new();
                decoded.append_to(&mut decoded_bytes);
                if !slice_starts_with(
                    &search_text[search_at..],
                    &decoded_bytes,
                    ascii_case_insensitive,
                ) {
                    return false;
                }

                haystack_at += consumed;
                search_at += decoded_bytes.len();
                continue;
            }
        }

        if !byte_eq(
            haystack[haystack_at],
            search_text[search_at],
            ascii_case_insensitive,
        ) {
            return false;
        }

        haystack_at += 1;
        search_at += 1;
    }

    true
}

fn slice_starts_with(value: &[u8], prefix: &[u8], ascii_case_insensitive: bool) -> bool {
    value.len() >= prefix.len()
        && value
            .iter()
            .zip(prefix.iter())
            .all(|(&left, &right)| byte_eq(left, right, ascii_case_insensitive))
}

fn byte_eq(left: u8, right: u8, ascii_case_insensitive: bool) -> bool {
    if ascii_case_insensitive {
        left.eq_ignore_ascii_case(&right)
    } else {
        left == right
    }
}

fn decode_character_reference(
    context: DecodeContext,
    input: &[u8],
) -> Option<(CharacterReference, usize)> {
    if input.len() < 3 || input[0] != b'&' {
        return None;
    }

    if input[1] == b'#' {
        let mut at = 2;
        let radix = if at < input.len() && (input[at] == b'x' || input[at] == b'X') {
            at += 1;
            16
        } else {
            10
        };
        let max_digits = if radix == 16 { 6 } else { 7 };

        let digits_start = at;
        while at < input.len() && input[at] == b'0' {
            at += 1;
        }
        let zero_count = at - digits_start;
        let significant_digits_start = at;
        while at < input.len()
            && if radix == 16 {
                input[at].is_ascii_hexdigit()
            } else {
                input[at].is_ascii_digit()
            }
        {
            at += 1;
        }
        let digit_count = at - significant_digits_start;

        if 0 == zero_count && 0 == digit_count {
            return None;
        }

        let consumed = if at < input.len() && input[at] == b';' { at + 1 } else { at };
        if 0 == digit_count || digit_count > max_digits {
            return Some((CharacterReference::Scalar('\u{FFFD}'), consumed));
        }

        let digits = std::str::from_utf8(&input[significant_digits_start..at]).ok()?;
        let value = u32::from_str_radix(digits, radix).ok()?;
        return Some((
            CharacterReference::Scalar(character_reference_code_point(value)),
            consumed,
        ));
    }

    let (decoded, name_len) = named_character_reference(&input[1..])?;
    let after_name = 1 + name_len;
    let has_semicolon = input[after_name - 1] == b';';

    if has_semicolon {
        return Some((decoded, after_name));
    }

    let ambiguous_follower = after_name < input.len()
        && (input[after_name].is_ascii_alphanumeric() || input[after_name] == b'=');

    if DecodeContext::Attribute == context && ambiguous_follower {
        return None;
    }

    Some((decoded, after_name))
}

fn character_reference_code_point(code_point: u32) -> char {
    let code_point = match code_point {
        0x80 => 0x20AC,
        0x82 => 0x201A,
        0x83 => 0x0192,
        0x84 => 0x201E,
        0x85 => 0x2026,
        0x86 => 0x2020,
        0x87 => 0x2021,
        0x88 => 0x02C6,
        0x89 => 0x2030,
        0x8A => 0x0160,
        0x8B => 0x2039,
        0x8C => 0x0152,
        0x8E => 0x017D,
        0x91 => 0x2018,
        0x92 => 0x2019,
        0x93 => 0x201C,
        0x94 => 0x201D,
        0x95 => 0x2022,
        0x96 => 0x2013,
        0x97 => 0x2014,
        0x98 => 0x02DC,
        0x99 => 0x2122,
        0x9A => 0x0161,
        0x9B => 0x203A,
        0x9C => 0x0153,
        0x9E => 0x017E,
        0x9F => 0x0178,
        other => other,
    };

    char::from_u32(code_point).unwrap_or('\u{FFFD}')
}

fn named_character_reference(input: &[u8]) -> Option<(CharacterReference, usize)> {
    let mut best = None;
    for (name, decoded) in html5_named_character_references::NAMED_CHARACTER_REFERENCES {
        if input.starts_with(name) && best.map(|(_, len)| name.len() > len).unwrap_or(true) {
            best = Some((*decoded, name.len()));
        }
    }

    best
}

#[cfg(test)]
mod tests {
    use super::{
        find_script_closer, scan_next_tag, scan_next_token, scan_next_token_in_namespace,
        AttributeValue, ScanResult, TagProcessor, TagScan, COMMENT_TYPE_INVALID, NAMESPACE_FOREIGN,
        NAMESPACE_HTML, TOKEN_TYPE_COMMENT, TOKEN_TYPE_FUNKY_COMMENT, TOKEN_TYPE_TAG,
        TOKEN_TYPE_TEXT,
    };
    use std::ptr;

    #[test]
    fn scans_basic_start_tag() {
        assert_eq!(
            scan_next_tag(b"one <div class=\"x\">two", 0).unwrap(),
            TagScan {
                tag_start: 4,
                tag_end: 19,
                name_start: 5,
                name_len: 3,
                is_closing: false,
                has_self_closing_flag: false,
                token_end: 19,
                token_type: TOKEN_TYPE_TAG,
            }
        );
    }

    #[test]
    fn scans_basic_closing_tag() {
        assert_eq!(
            scan_next_tag(b"<p>text</p>", 3).unwrap(),
            TagScan {
                tag_start: 7,
                tag_end: 11,
                name_start: 9,
                name_len: 1,
                is_closing: true,
                has_self_closing_flag: false,
                token_end: 11,
                token_type: TOKEN_TYPE_TAG,
            }
        );
    }

    #[test]
    fn skips_non_tag_less_than_sequences() {
        let scan = scan_next_tag(b"1 < 2 <!-- comment --> <span>", 0).unwrap();

        assert_eq!(scan.tag_start, 23);
        assert_eq!(scan.name_start, 24);
        assert_eq!(scan.name_len, 4);
    }

    #[test]
    fn ignores_gt_inside_quoted_attributes() {
        let scan = scan_next_tag(br#"<div title="1 > 0">ok</div>"#, 0).unwrap();

        assert_eq!(scan.tag_end, 19);
    }

    #[test]
    fn reports_incomplete_tag_as_not_found() {
        assert!(scan_next_tag(br#"<div title="unterminated"#, 0).is_none());
    }

    #[test]
    fn treats_bare_less_than_at_eof_as_text() {
        let ScanResult::Token(scan) = scan_next_token(b"<", 0) else {
            panic!("Expected a text token.");
        };

        assert_eq!(scan.token_type, TOKEN_TYPE_TEXT);
        assert_eq!(scan.tag_start, 0);
        assert_eq!(scan.token_end, 1);
    }

    #[test]
    fn treats_bare_closing_less_than_at_eof_as_text() {
        let ScanResult::Token(scan) = scan_next_token(b"</", 0) else {
            panic!("Expected a text token.");
        };

        assert_eq!(scan.token_type, TOKEN_TYPE_TEXT);
        assert_eq!(scan.tag_start, 0);
        assert_eq!(scan.token_end, 2);
    }

    #[test]
    fn scanner_tracks_script_double_escaped_state() {
        let html = b"<script><!--<script></script><script></script><span></span></script><div>";
        let script = scan_next_tag(html, 0).unwrap();
        assert_eq!(&html[script.name_start..script.name_start + script.name_len], b"script");

        let div = scan_next_tag(html, script.token_end).unwrap();
        assert_eq!(&html[div.name_start..div.name_start + div.name_len], b"div");
    }

    #[test]
    fn scanner_reports_unclosed_atomic_tags_at_eof() {
        let mut processor = TagProcessor {
            html: b"<script>text".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe { super::wp_html_api_rust_tag_processor_next_token(&mut processor) });
        let scan = processor.current.unwrap();
        assert_eq!(&processor.html[scan.name_start..scan.name_start + scan.name_len], b"script");
        assert_eq!(scan.tag_end, b"<script>".len());
        assert_eq!(scan.token_end, processor.html.len());
        assert_eq!(processor.current_modifiable_text(scan).unwrap(), b"text");

        assert!(!unsafe { super::wp_html_api_rust_tag_processor_next_token(&mut processor) });
        assert!(!processor.paused_at_incomplete);
    }

    #[test]
    fn scanner_reports_unclosed_textarea_at_eof() {
        let mut processor = TagProcessor {
            html: b"<textarea><option>".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe { super::wp_html_api_rust_tag_processor_next_token(&mut processor) });
        let scan = processor.current.unwrap();
        assert_eq!(&processor.html[scan.name_start..scan.name_start + scan.name_len], b"textarea");
        assert_eq!(scan.tag_end, b"<textarea>".len());
        assert_eq!(scan.token_end, processor.html.len());
        assert_eq!(processor.current_modifiable_text(scan).unwrap(), b"<option>");
    }

    #[test]
    fn scanner_keeps_partial_atomic_closer_text_until_delimiter() {
        for (html, expected) in [
            (&b"<script></S"[..], &b"</S"[..]),
            (&b"<script></SCRIPT"[..], &b"</SCRIPT"[..]),
            (&b"<script></SCRIPT "[..], &b""[..]),
        ] {
            let mut processor = TagProcessor {
                html: html.to_vec(),
                offset: 0,
                current: None,
                scratch: Vec::new(),
                paused_at_incomplete: false,
                inserted_attributes: Vec::new(),
                parsing_namespace: NAMESPACE_HTML,
            };

            assert!(unsafe { super::wp_html_api_rust_tag_processor_next_token(&mut processor) });
            let scan = processor.current.unwrap();
            assert_eq!(processor.current_modifiable_text(scan).unwrap(), expected);
        }
    }

    #[test]
    fn scanner_keeps_script_double_escaped_after_bogus_comment_end() {
        let html = b"<script><!--<script>--!></script>X";

        assert!(find_script_closer(html, b"<script>".len()).is_none());
    }

    #[test]
    fn scanner_does_not_extend_foreign_script_to_closer() {
        let html = b"<script /><g>";
        let ScanResult::Token(script) = scan_next_token_in_namespace(html, 0, NAMESPACE_FOREIGN)
        else {
            panic!("Expected foreign script token.");
        };

        assert_eq!(&html[script.name_start..script.name_start + script.name_len], b"script");
        assert_eq!(script.token_end, b"<script />".len());
        assert!(script.has_self_closing_flag);

        let ScanResult::Token(g) =
            scan_next_token_in_namespace(html, script.token_end, NAMESPACE_FOREIGN)
        else {
            panic!("Expected following g token.");
        };

        assert_eq!(&html[g.name_start..g.name_start + g.name_len], b"g");
    }

    #[test]
    fn set_modifiable_text_preserves_atomic_tag_boundary() {
        let mut processor = TagProcessor {
            html: b"<script></script>".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(
                &mut processor,
                b"script".as_ptr(),
                b"script".len(),
                false,
            )
        });
        assert!(processor.set_modifiable_text(b"different text"));

        let scan = processor.current.unwrap();
        assert_eq!(scan.tag_end, b"<script>".len());
        assert_eq!(
            processor.current_modifiable_text(scan).unwrap(),
            b"different text"
        );
    }

    #[test]
    fn set_modifiable_text_normalizes_textarea_newlines() {
        for (plaintext, expected_html, expected_text) in [
            (
                &b"\rCR"[..],
                &b"<textarea>\n\nCR</textarea>"[..],
                &b"\nCR"[..],
            ),
            (
                &b"\r\nCR-N"[..],
                &b"<textarea>\n\nCR-N</textarea>"[..],
                &b"\nCR-N"[..],
            ),
        ] {
            let mut processor = TagProcessor {
                html: b"<textarea></textarea>".to_vec(),
                offset: 0,
                current: None,
                scratch: Vec::new(),
                paused_at_incomplete: false,
                inserted_attributes: Vec::new(),
                parsing_namespace: NAMESPACE_HTML,
            };

            assert!(unsafe {
                super::wp_html_api_rust_tag_processor_next_tag(
                    &mut processor,
                    b"textarea".as_ptr(),
                    b"textarea".len(),
                    false,
                )
            });
            assert!(processor.set_modifiable_text(plaintext));

            let scan = processor.current.unwrap();
            assert_eq!(&processor.html, expected_html);
            assert_eq!(processor.current_modifiable_text(scan).unwrap(), expected_text);
        }
    }

    #[test]
    fn scanner_closes_incorrectly_closed_comments() {
        let html = b"<img id=before><!-- <img id=inside> --!><img id=after>--><img id=final>";
        let before = scan_next_tag(html, 0).unwrap();
        assert_eq!(&html[before.name_start..before.name_start + before.name_len], b"img");

        let after = scan_next_tag(html, before.token_end).unwrap();
        assert_eq!(&html[after.name_start..after.name_start + after.name_len], b"img");
        assert!(html[after.tag_start..after.token_end].starts_with(b"<img id=after"));
    }

    #[test]
    fn scanner_does_not_close_bang_empty_comment_too_early() {
        let html = b"<hr><!--!><hr id=inside>--><hr id=after>";
        let first = scan_next_tag(html, 0).unwrap();
        let after = scan_next_tag(html, first.token_end).unwrap();
        assert!(html[after.tag_start..after.token_end].starts_with(b"<hr id=after"));
    }

    #[test]
    fn scanner_reports_unclosed_comments_incomplete() {
        let html = b"FOO<!-- BAR --! >BAZ";
        let mut processor = TagProcessor {
            html: html.to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_token(&mut processor)
        });
        assert_eq!(
            processor.current_modifiable_text(processor.current.unwrap()).unwrap(),
            b"FOO"
        );

        assert!(!unsafe { super::wp_html_api_rust_tag_processor_next_token(&mut processor) });
        assert!(processor.paused_at_incomplete);

        for html in [
            &b"<!--"[..],
            &b"<!--x"[..],
            &b"<!--x--"[..],
            &b"<!--x--!"[..],
            &b"<!--x--! >"[..],
        ] {
            assert!(matches!(scan_next_token(html, 0), ScanResult::Incomplete));
        }
    }

    #[test]
    fn scanner_consumes_eof_terminated_bogus_comments() {
        let ScanResult::Token(scan) = scan_next_token(b"</#", 0) else {
            panic!("Expected a funky comment token.");
        };

        assert_eq!(scan.token_type, TOKEN_TYPE_FUNKY_COMMENT);
        assert_eq!(scan.tag_start, 0);
        assert_eq!(scan.token_end, 3);

        let mut processor = TagProcessor {
            html: b"</#".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_token(&mut processor)
        });

        let scan = processor.current.unwrap();
        assert_eq!(processor.current_modifiable_text(scan).unwrap(), b"#");
        assert!(!processor.paused_at_incomplete);
    }

    #[test]
    fn scanner_consumes_eof_terminated_question_comments() {
        let ScanResult::Token(scan) = scan_next_token(b"<?", 0) else {
            panic!("Expected an invalid comment token.");
        };

        assert_eq!(scan.token_type, TOKEN_TYPE_COMMENT);
        assert_eq!(scan.tag_start, 0);
        assert_eq!(scan.token_end, 2);

        let mut processor = TagProcessor {
            html: b"<?".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_token(&mut processor)
        });

        let scan = processor.current.unwrap();
        assert_eq!(processor.comment_type(scan), COMMENT_TYPE_INVALID);
        assert_eq!(processor.current_modifiable_text(scan).unwrap(), b"");
        assert!(!processor.paused_at_incomplete);
    }

    #[test]
    fn invalid_processing_instruction_keeps_target_in_modifiable_text() {
        let mut processor = TagProcessor {
            html: b"<?xml foo >".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_token(&mut processor)
        });

        let scan = processor.current.unwrap();
        assert_eq!(processor.comment_type(scan), COMMENT_TYPE_INVALID);
        assert_eq!(processor.current_modifiable_text(scan).unwrap(), b"xml foo ");
    }

    #[test]
    fn data_character_references_use_legacy_prefix_matches() {
        assert_eq!(
            super::transform_text(
                b"FOO&gtBAR I'm &notit; I tell you",
                true,
                super::NullTransform::Replace
            ),
            "FOO>BAR I'm ¬it; I tell you".as_bytes()
        );
    }

    #[test]
    fn attribute_character_references_preserve_ambiguous_legacy_matches() {
        assert_eq!(super::decode_html_attribute(b"&notit;"), b"&notit;");
        assert_eq!(super::decode_html_attribute(b"&not;"), "¬".as_bytes());
    }

    #[test]
    fn numeric_character_references_follow_html_replacement_rules() {
        assert_eq!(
            super::transform_text(
                b"FOO&#x0000;ZOO &#x0080; &#xD800; &#x110000; &#11111111111",
                true,
                super::NullTransform::Replace
            ),
            "FOO�ZOO € � � �".as_bytes()
        );
    }

    #[test]
    fn additional_named_character_references_cover_html5lib_cases() {
        assert_eq!(
            super::transform_text(
                b"&lang;&rang; &ImaginaryI; &Kopf; &Gopf; &notinva; &AMP &NotEqualTilde;A &ThickSpace;A &NotSubset;A",
                true,
                super::NullTransform::Replace
            ),
            "⟨⟩ ⅈ 𝕂 𝔾 ∉ & ≂̸A   A ⊂⃒A".as_bytes()
        );
        assert_eq!(
            super::decode_html_attribute(b"ZZ&pound_id=23 ZZ&pound;_id=23 ZZ&prod;_id=23"),
            "ZZ£_id=23 ZZ£_id=23 ZZ∏_id=23".as_bytes()
        );
        assert_eq!(
            super::transform_text(b"ZZ&pound=23", true, super::NullTransform::Replace),
            "ZZ£=23".as_bytes()
        );
        assert_eq!(
            super::transform_text(b"ZZ&AElig=", true, super::NullTransform::Replace),
            "ZZÆ=".as_bytes()
        );
        assert_eq!(super::decode_html_attribute(b"ZZ&AElig="), b"ZZ&AElig=");
        assert_eq!(
            super::decode_html_attribute(b"ZZ&AElig;"),
            "ZZÆ".as_bytes()
        );
    }

    #[test]
    fn named_character_references_cover_wordpress_html5_table() {
        assert_eq!(
            super::transform_text(
                b"&reg; &trade; &mdash; &rsquo; &euro; &CounterClockwiseContourIntegral; &NotNestedGreaterGreater;",
                true,
                super::NullTransform::Replace
            ),
            "® ™ — ’ € ∳ ⪢̸".as_bytes()
        );
        assert_eq!(
            super::decode_html_attribute(b"&reg=1 &reg;=1 &plusmn=1 &plusmn;=1 &apos=1 &apos;=1"),
            "&reg=1 ®=1 &plusmn=1 ±=1 &apos=1 '=1".as_bytes()
        );
    }

    #[test]
    fn text_transform_normalizes_line_endings() {
        assert_eq!(
            super::transform_text(
                b"one\r\ntwo\rthree\nfour",
                false,
                super::NullTransform::Replace
            ),
            b"one\ntwo\nthree\nfour"
        );
    }

    #[test]
    fn pre_text_strips_initial_carriage_return() {
        let mut processor = TagProcessor {
            html: b"<pre>\rA\r\nB\rC</pre>".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe { super::wp_html_api_rust_tag_processor_next_token(&mut processor) });
        assert!(unsafe { super::wp_html_api_rust_tag_processor_next_token(&mut processor) });

        let scan = processor.current.unwrap();
        assert_eq!(
            processor.current_modifiable_text(scan).unwrap(),
            b"A\nB\nC"
        );
    }

    #[test]
    fn foreign_cdata_normalizes_line_endings() {
        let processor = TagProcessor {
            html: b"<![CDATA[A\r\nB\rC]]>".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_FOREIGN,
        };

        let ScanResult::Token(scan) =
            scan_next_token_in_namespace(&processor.html, 0, NAMESPACE_FOREIGN)
        else {
            panic!("Expected CDATA token.");
        };

        assert_eq!(
            processor.current_modifiable_text(scan).unwrap(),
            b"A\nB\nC"
        );
    }

    #[test]
    fn foreign_incomplete_cdata_consumes_rest_as_text() {
        for (html, expected) in [
            (&b"<![CDATA[]>a"[..], &b"]>a"[..]),
            (&b"<![CDATA[<svg>a"[..], &b"<svg>a"[..]),
            (&b"<![CDATA[</svg>a"[..], &b"</svg>a"[..]),
        ] {
            let processor = TagProcessor {
                html: html.to_vec(),
                offset: 0,
                current: None,
                scratch: Vec::new(),
                paused_at_incomplete: false,
                inserted_attributes: Vec::new(),
                parsing_namespace: NAMESPACE_FOREIGN,
            };

            let ScanResult::Token(scan) =
                scan_next_token_in_namespace(&processor.html, 0, NAMESPACE_FOREIGN)
            else {
                panic!("Expected CDATA token.");
            };

            assert_eq!(scan.token_type, super::TOKEN_TYPE_CDATA);
            assert_eq!(scan.token_end, processor.html.len());
            assert_eq!(processor.current_modifiable_text(scan).unwrap(), expected);
        }
    }

    #[test]
    fn foreign_text_replaces_null_bytes() {
        let processor = TagProcessor {
            html: b"one\0two".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_FOREIGN,
        };

        let ScanResult::Token(scan) =
            scan_next_token_in_namespace(&processor.html, 0, NAMESPACE_FOREIGN)
        else {
            panic!("Expected foreign text token.");
        };

        assert_eq!(
            processor.current_modifiable_text(scan).unwrap(),
            "one\u{FFFD}two".as_bytes()
        );
    }

    #[test]
    fn text_subdivision_splits_leading_whitespace_from_mixed_text() {
        let mut processor = TagProcessor {
            html: b" a <frameset>".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe { super::wp_html_api_rust_tag_processor_next_token(&mut processor) });
        assert_eq!(
            unsafe { super::wp_html_api_rust_tag_processor_subdivide_text_appropriately(&mut processor) },
            2
        );
        let scan = processor.current.unwrap();
        assert_eq!(scan.token_end, 1);
        assert_eq!(processor.offset, 1);
        assert_eq!(processor.current_modifiable_text(scan).unwrap(), b" ");

        assert!(unsafe { super::wp_html_api_rust_tag_processor_next_token(&mut processor) });
        let scan = processor.current.unwrap();
        assert_eq!(processor.current_modifiable_text(scan).unwrap(), b"a ");
    }

    #[test]
    fn text_subdivision_splits_leading_null_sequence() {
        let mut processor = TagProcessor {
            html: b"\0\0<frameset>".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe { super::wp_html_api_rust_tag_processor_next_token(&mut processor) });
        assert_eq!(
            unsafe { super::wp_html_api_rust_tag_processor_subdivide_text_appropriately(&mut processor) },
            1
        );
        let scan = processor.current.unwrap();
        assert_eq!(scan.token_end, 2);
        assert_eq!(processor.offset, 2);
    }

    #[test]
    fn html_cdata_lookalike_closes_at_first_greater_than() {
        let html = b"<![CDATA[This <is> a <strong id=\"yes\">HTML Tag</strong>]]>";
        let tag = scan_next_tag(html, 0).unwrap();
        assert_eq!(&html[tag.name_start..tag.name_start + tag.name_len], b"strong");
    }

    #[test]
    fn tag_processor_finds_start_tags_matching_query() {
        let mut processor = TagProcessor {
            html: b"<div><span></span><p>".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, b"p".as_ptr(), 1, false)
        });

        assert_eq!(
            processor.current.unwrap(),
            TagScan {
                tag_start: 18,
                tag_end: 21,
                name_start: 19,
                name_len: 1,
                is_closing: false,
                has_self_closing_flag: false,
                token_end: 21,
                token_type: TOKEN_TYPE_TAG,
            }
        );
    }

    #[test]
    fn tag_processor_retains_current_token_after_eof() {
        let mut processor = TagProcessor {
            html: b"<!DOCTYPE html><body>".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, b"body".as_ptr(), 4, false)
        });
        let body = processor.current.unwrap();

        assert!(!unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(
                &mut processor,
                b"missing".as_ptr(),
                b"missing".len(),
                false,
            )
        });
        assert_eq!(processor.current.unwrap(), body);
    }

    #[test]
    fn tag_processor_can_visit_closing_tags() {
        let mut processor = TagProcessor {
            html: b"<div></div>".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, b"div".as_ptr(), 3, true)
        });
        assert!(!unsafe { super::wp_html_api_rust_tag_processor_is_tag_closer(&processor) });

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, b"div".as_ptr(), 3, true)
        });
        assert!(unsafe { super::wp_html_api_rust_tag_processor_is_tag_closer(&processor) });
    }

    #[test]
    fn tag_processor_reports_single_character_end_tag_as_closer() {
        let mut processor = TagProcessor {
            html: b"<b></b>".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, b"b".as_ptr(), 1, true)
        });
        assert!(!unsafe { super::wp_html_api_rust_tag_processor_is_tag_closer(&processor) });

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, b"b".as_ptr(), 1, true)
        });
        assert!(unsafe { super::wp_html_api_rust_tag_processor_is_tag_closer(&processor) });
    }

    #[test]
    fn tag_processor_reports_self_closing_flag() {
        let mut processor = TagProcessor {
            html: b"<div / ><img/>".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, ptr::null(), 0, false)
        });
        assert!(!unsafe { super::wp_html_api_rust_tag_processor_has_self_closing_flag(&processor) });

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, ptr::null(), 0, false)
        });
        assert!(unsafe { super::wp_html_api_rust_tag_processor_has_self_closing_flag(&processor) });
    }

    #[test]
    fn tag_processor_keeps_trailing_slash_in_unquoted_attribute_value() {
        let mut processor = TagProcessor {
            html: b"<foo bar=qux/><foo bar=qux />".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, ptr::null(), 0, false)
        });
        assert!(unsafe { super::wp_html_api_rust_tag_processor_has_self_closing_flag(&processor) });
        assert!(matches!(processor.get_attribute(b"bar"), AttributeValue::String));
        assert_eq!(processor.scratch, b"qux/");

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, ptr::null(), 0, false)
        });
        assert!(unsafe { super::wp_html_api_rust_tag_processor_has_self_closing_flag(&processor) });
        assert!(matches!(processor.get_attribute(b"bar"), AttributeValue::String));
        assert_eq!(processor.scratch, b"qux");
    }

    #[test]
    fn tag_processor_reads_attributes() {
        let mut processor = TagProcessor {
            html: br#"<div enabled DATA-id="the &quot;one&quot;" a/b/c="test">"#.to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, ptr::null(), 0, false)
        });

        assert!(matches!(processor.get_attribute(b"enabled"), AttributeValue::Boolean));
        assert!(matches!(processor.get_attribute(b"data-ID"), AttributeValue::String));
        assert_eq!(processor.scratch, b"the \"one\"");
        assert!(matches!(processor.get_attribute(b"a"), AttributeValue::Boolean));
        assert!(matches!(processor.get_attribute(b"b"), AttributeValue::Boolean));
        assert!(matches!(processor.get_attribute(b"c"), AttributeValue::String));
        assert_eq!(processor.scratch, b"test");
    }

    #[test]
    fn tag_processor_normalizes_nulls_in_attribute_names() {
        let mut processor = TagProcessor {
            html: b"<img/\0id=5>".to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, ptr::null(), 0, false)
        });

        assert!(processor.get_attribute_names_with_prefix(b""));
        assert_eq!(processor.scratch, "\u{FFFD}id".as_bytes());
        assert!(matches!(
            processor.get_attribute("\u{FFFD}id".as_bytes()),
            AttributeValue::String
        ));
        assert_eq!(processor.scratch, b"5");
    }

    #[test]
    fn tag_processor_sets_and_removes_attributes() {
        let mut processor = TagProcessor {
            html: br#"<div DATA-enabled="true" id="first">Text</div>"#.to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, ptr::null(), 0, false)
        });

        assert!(processor.set_attribute(b"data-enabled", b"abc", 2));
        assert_eq!(
            std::str::from_utf8(&processor.html).unwrap(),
            r#"<div data-enabled="abc" id="first">Text</div>"#
        );

        assert!(processor.set_attribute(b"test", br#""<&"#, 2));
        assert_eq!(
            std::str::from_utf8(&processor.html).unwrap(),
            r#"<div test="&quot;&lt;&amp;" data-enabled="abc" id="first">Text</div>"#
        );

        assert!(processor.remove_attribute(b"data-enabled"));
        assert_eq!(
            std::str::from_utf8(&processor.html).unwrap(),
            r#"<div test="&quot;&lt;&amp;"  id="first">Text</div>"#
        );
    }

    #[test]
    fn tag_processor_lists_attribute_names_with_prefix() {
        let mut processor = TagProcessor {
            html: br#"<div data-ENABLED data-enabled="ignored" data-test-id="14" id="first">"#.to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, ptr::null(), 0, false)
        });

        assert!(processor.get_attribute_names_with_prefix(b"data-"));
        assert_eq!(processor.scratch, b"data-enabled\0data-test-id");

        assert!(processor.get_attribute_names_with_prefix(b"aria-"));
        assert!(processor.scratch.is_empty());
    }

    #[test]
    fn tag_processor_updates_classes() {
        let mut processor = TagProcessor {
            html: br#"<div class="one two">"#.to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, ptr::null(), 0, false)
        });

        assert_eq!(processor.has_class(b"one", false), 2);
        assert_eq!(processor.has_class(b"three", false), 1);
        assert!(processor.add_class(b"three", false));
        assert!(processor.remove_class(b"two", false));
        assert!(processor.class_list(false));
        assert_eq!(processor.scratch, b"one\x1fthree");
        assert_eq!(
            std::str::from_utf8(&processor.html).unwrap(),
            r#"<div class="one three">"#
        );
    }

    #[test]
    fn tag_processor_preserves_raw_class_update_names() {
        let mut processor = TagProcessor {
            html: "<div class=\"x\u{FFFD}y\">".as_bytes().to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, ptr::null(), 0, false)
        });

        assert_eq!(processor.has_class(b"x\0y", false), 1);
        assert!(processor.add_class(b"x\0y", false));
        assert_eq!(
            processor.html.as_slice(),
            "<div class=\"x\u{FFFD}y x\0y\">".as_bytes()
        );
        assert!(processor.class_list(false));
        assert_eq!(processor.scratch, "x\u{FFFD}y".as_bytes());
        assert_eq!(processor.has_class(b"x\0y", false), 1);

        assert!(processor.remove_class(b"x\0y", false));
        assert_eq!(
            processor.html.as_slice(),
            "<div class=\"x\u{FFFD}y\">".as_bytes()
        );
    }

    #[test]
    fn tag_processor_matches_classes_case_insensitively_in_quirks_mode() {
        let mut processor = TagProcessor {
            html: "<span class=\"UPPER A a E\u{301} e\u{301}\">"
                .as_bytes()
                .to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, ptr::null(), 0, false)
        });

        assert_eq!(processor.has_class(b"upper", true), 2);
        assert!(processor.add_class(b"upper", true));
        assert_eq!(
            std::str::from_utf8(&processor.html).unwrap(),
            "<span class=\"UPPER A a E\u{301} e\u{301}\">"
        );

        assert!(processor.add_class(b"ANOTHER-UPPER", true));
        assert!(processor.class_list(true));
        assert_eq!(
            processor.scratch,
            b"upper\x1fa\x1fe\xcc\x81\x1fanother-upper"
        );
        assert_eq!(
            std::str::from_utf8(&processor.html).unwrap(),
            "<span class=\"UPPER A a E\u{301} e\u{301} ANOTHER-UPPER\">"
        );

        assert!(processor.remove_class(b"upper", true));
        assert_eq!(
            std::str::from_utf8(&processor.html).unwrap(),
            "<span class=\"A E\u{301} ANOTHER-UPPER\">"
        );
    }

    #[test]
    fn tag_processor_trims_class_edges_when_adding_class() {
        let mut processor = TagProcessor {
            html: br#"<div class="   main   with-border   ">"#.to_vec(),
            offset: 0,
            current: None,
            scratch: Vec::new(),
            paused_at_incomplete: false,
            inserted_attributes: Vec::new(),
            parsing_namespace: NAMESPACE_HTML,
        };

        assert!(unsafe {
            super::wp_html_api_rust_tag_processor_next_tag(&mut processor, ptr::null(), 0, false)
        });

        assert!(processor.add_class(b"foo-class", false));
        assert_eq!(
            std::str::from_utf8(&processor.html).unwrap(),
            r#"<div class="main   with-border foo-class">"#
        );
    }
}
