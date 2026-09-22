use html5ever::tendril::TendrilSink;
use html5ever::tree_builder::TreeBuilderOpts;
use html5ever::{parse_document, parse_fragment, ParseOpts, QualName};
use markup5ever_rcdom::{Handle, NodeData, RcDom};
use std::env;
use std::fs;
use std::io::Cursor;
use std::process::ExitCode;

const HTML5EVER_VERSION: &str = "0.39.0";
const HTML5EVER_CHECKSUM: &str =
    "46a1761807faccc9a19e86944bbf40610014066306f96edcdedc2fb714bcb7b8";
const RCDOM_VERSION: &str = "0.39.0+unofficial";
const RCDOM_CHECKSUM: &str =
    "3ac010f19d6c4af81eeb4018a39d7a115de9d285af45c126a4ac02e6fc5716b7";
const RUST_TOOLCHAIN: &str = "1.88.0";
const BUILD_IDENTITY: &str = env!("HTML_API_FUZZ_HTML5EVER_BUILD_IDENTITY");
const CARGO_LOCK_SHA256: &str = env!("HTML_API_FUZZ_HTML5EVER_CARGO_LOCK_SHA256");
const HTML_NS: &str = "http://www.w3.org/1999/xhtml";
const SVG_NS: &str = "http://www.w3.org/2000/svg";
const MATH_NS: &str = "http://www.w3.org/1998/Math/MathML";
const XLINK_NS: &str = "http://www.w3.org/1999/xlink";
const XML_NS: &str = "http://www.w3.org/XML/1998/namespace";
const XMLNS_NS: &str = "http://www.w3.org/2000/xmlns/";

#[derive(Default)]
struct Options {
    mode: Option<String>,
    context: Option<String>,
    input: Option<String>,
    max_nodes: usize,
    max_depth: usize,
    max_tree_bytes: usize,
    help: bool,
    version: bool,
}

struct RenderState {
    tree: String,
    node_count: usize,
    max_nodes: usize,
    max_depth: usize,
    max_tree_bytes: usize,
}

#[derive(Debug)]
struct OracleError {
    failure_class: &'static str,
    message: String,
    node_count: usize,
}

fn main() -> ExitCode {
    if !identity_is_valid(BUILD_IDENTITY, CARGO_LOCK_SHA256) {
        print_error(
            "oracle-identity-error",
            "Embedded html5ever build identity is missing or malformed.",
            0,
        );
        return ExitCode::FAILURE;
    }

    let options = match parse_args(env::args().skip(1)) {
        Ok(options) => options,
        Err(message) => {
            print_error("oracle-cli-error", &message, 0);
            return ExitCode::FAILURE;
        }
    };

    if options.help {
        print_usage();
        return ExitCode::SUCCESS;
    }
    if options.version {
        println!(
            "{{\"status\":\"ok\",\"oracle\":{}}}",
            oracle_json()
        );
        return ExitCode::SUCCESS;
    }

    let input_path = options.input.as_deref().expect("validated input path");
    let input = match fs::read(input_path) {
        Ok(input) => input,
        Err(error) => {
            print_error(
                "oracle-cli-error",
                &format!("Could not read input file: {error}"),
                0,
            );
            return ExitCode::FAILURE;
        }
    };

    match render(&options, &input) {
        Ok((tree, node_count)) => {
            print_ok(&tree, node_count);
            ExitCode::SUCCESS
        }
        Err(error) => {
            print_error(error.failure_class, &error.message, error.node_count);
            ExitCode::SUCCESS
        }
    }
}

fn parse_args(args: impl Iterator<Item = String>) -> Result<Options, String> {
    let mut options = Options {
        max_nodes: 3000,
        max_depth: 512,
        max_tree_bytes: 16_777_216,
        ..Options::default()
    };
    let mut args = args.peekable();
    let mut max_nodes_set = false;
    let mut max_depth_set = false;
    let mut max_tree_bytes_set = false;

    while let Some(arg) = args.next() {
        match arg.as_str() {
            "--help" | "-h" => {
                if options.help {
                    return Err("Duplicate --help option.".to_string());
                }
                options.help = true;
            }
            "--version" => {
                if options.version {
                    return Err("Duplicate --version option.".to_string());
                }
                options.version = true;
            }
            "--mode" => set_once(&mut options.mode, next_value(&mut args, "--mode")?, "--mode")?,
            "--context" => set_once(
                &mut options.context,
                next_value(&mut args, "--context")?,
                "--context",
            )?,
            "--input" => set_once(
                &mut options.input,
                next_value(&mut args, "--input")?,
                "--input",
            )?,
            "--max-nodes" => {
                if max_nodes_set {
                    return Err("Duplicate --max-nodes option.".to_string());
                }
                max_nodes_set = true;
                let value = next_value(&mut args, "--max-nodes")?;
                options.max_nodes = value.parse::<usize>().map_err(|_| {
                    "Expected --max-nodes to be a positive integer.".to_string()
                })?;
                if options.max_nodes == 0 {
                    return Err("Expected --max-nodes to be a positive integer.".to_string());
                }
            }
            "--max-depth" => {
                if max_depth_set {
                    return Err("Duplicate --max-depth option.".to_string());
                }
                max_depth_set = true;
                let value = next_value(&mut args, "--max-depth")?;
                options.max_depth = value.parse::<usize>().map_err(|_| {
                    "Expected --max-depth to be a positive integer.".to_string()
                })?;
                if options.max_depth == 0 {
                    return Err("Expected --max-depth to be a positive integer.".to_string());
                }
            }
            "--max-tree-bytes" => {
                if max_tree_bytes_set {
                    return Err("Duplicate --max-tree-bytes option.".to_string());
                }
                max_tree_bytes_set = true;
                let value = next_value(&mut args, "--max-tree-bytes")?;
                options.max_tree_bytes = value.parse::<usize>().map_err(|_| {
                    "Expected --max-tree-bytes to be a positive integer.".to_string()
                })?;
                if options.max_tree_bytes == 0 {
                    return Err("Expected --max-tree-bytes to be a positive integer.".to_string());
                }
            }
            _ => return Err(format!("Unknown option: {arg}")),
        }
    }

    if options.help || options.version {
        if options.help && options.version {
            return Err("--help and --version cannot be combined.".to_string());
        }
        if options.mode.is_some()
            || options.context.is_some()
            || options.input.is_some()
            || max_nodes_set
            || max_depth_set
            || max_tree_bytes_set
        {
            let flag = if options.help { "--help" } else { "--version" };
            return Err(format!("{flag} cannot be combined with parse options."));
        }
        return Ok(options);
    }

    let mode = options
        .mode
        .as_deref()
        .ok_or_else(|| "Missing --mode.".to_string())?;
    if mode != "full-document" && !mode.starts_with("fragment-") {
        return Err("Expected --mode full-document or fragment-*.".to_string());
    }
    if options.input.is_none() {
        return Err("Missing --input.".to_string());
    }

    Ok(options)
}

fn set_once(slot: &mut Option<String>, value: String, option: &str) -> Result<(), String> {
    if slot.is_some() {
        return Err(format!("Duplicate {option} option."));
    }
    *slot = Some(value);
    Ok(())
}

fn next_value(
    args: &mut std::iter::Peekable<impl Iterator<Item = String>>,
    option: &str,
) -> Result<String, String> {
    args.next()
        .ok_or_else(|| format!("Missing value for {option}."))
}

fn print_usage() {
    println!(
        "Usage: html5ever-tree-oracle --mode full-document|fragment-* --input PATH [--context TAG] [--max-nodes N] [--max-depth N] [--max-tree-bytes N]"
    );
}

fn render(options: &Options, input: &[u8]) -> Result<(String, usize), OracleError> {
    if std::str::from_utf8(input).is_err() {
        return Err(OracleError {
            failure_class: "oracle-unsupported",
            message: "html5ever source input is not valid UTF-8; refusing lossy transcoding."
                .to_string(),
            node_count: 0,
        });
    }
    let mode = options.mode.as_deref().expect("validated mode");
    let (dom, is_fragment) = if mode == "full-document" {
        (parse_document_bytes(input)?, false)
    } else {
        let context = options
            .context
            .as_deref()
            .or_else(|| mode.strip_prefix("fragment-"))
            .unwrap_or("body");
        (parse_fragment_bytes(input, context)?, true)
    };

    let mut state = RenderState {
        tree: String::new(),
        node_count: 0,
        max_nodes: options.max_nodes,
        max_depth: options.max_depth,
        max_tree_bytes: options.max_tree_bytes,
    };
    if is_fragment {
        render_fragment_children(&dom, &mut state)?;
    } else {
        render_children(&dom.document, 0, &mut state)?;
    }
    append_output(&mut state, "\n")?;
    Ok((state.tree, state.node_count))
}

fn render_fragment_children(dom: &RcDom, state: &mut RenderState) -> Result<(), OracleError> {
    // RcDom attaches fragment output below a synthetic HTML document element.
    // That implementation detail is not part of the fragment result and must
    // not affect either the canonical tree or its node count.
    let container = dom
        .document
        .children
        .borrow()
        .iter()
        .find(|node| {
            matches!(
                &node.data,
                NodeData::Element { name, .. }
                    if name.ns.as_ref() == HTML_NS && name.local.as_ref() == "html"
            )
        })
        .cloned();

    match container {
        Some(container) => render_children(&container, 0, state),
        None => render_children(&dom.document, 0, state),
    }
}

fn parse_document_bytes(input: &[u8]) -> Result<RcDom, OracleError> {
    parse_document(RcDom::default(), scripting_disabled_parse_options())
        .from_utf8()
        .read_from(&mut Cursor::new(input))
        .map_err(|error| OracleError {
            failure_class: "oracle-parse-error",
            message: format!("html5ever could not read the input: {error}"),
            node_count: 0,
        })
}

fn parse_fragment_bytes(input: &[u8], context: &str) -> Result<RcDom, OracleError> {
    let context_name = context_qual_name(context).ok_or_else(|| OracleError {
        failure_class: "oracle-unsupported",
        message: format!("Unsupported fragment context: {context}"),
        node_count: 0,
    })?;

    parse_fragment(
        RcDom::default(),
        scripting_disabled_parse_options(),
        context_name,
        Vec::new(),
        false,
    )
    .from_utf8()
    .read_from(&mut Cursor::new(input))
    .map_err(|error| OracleError {
        failure_class: "oracle-parse-error",
        message: format!("html5ever could not read the fragment: {error}"),
        node_count: 0,
    })
}

fn scripting_disabled_parse_options() -> ParseOpts {
    ParseOpts {
        tree_builder: TreeBuilderOpts {
            scripting_enabled: false,
            ..TreeBuilderOpts::default()
        },
        ..ParseOpts::default()
    }
}

fn context_qual_name(context: &str) -> Option<QualName> {
    let lower = context.to_ascii_lowercase();
    let namespace = match lower.as_str() {
        "body" | "div" | "p" | "td" | "tr" | "table" | "caption" | "colgroup"
        | "select" | "option" | "template" | "title" | "textarea" | "script"
        | "style" => HTML_NS,
        "svg" => SVG_NS,
        "math" => MATH_NS,
        _ => return None,
    };

    Some(QualName::new(
        None,
        namespace.into(),
        lower.as_str().into(),
    ))
}

fn render_children(
    parent: &Handle,
    indent: usize,
    state: &mut RenderState,
) -> Result<(), OracleError> {
    for child in parent.children.borrow().iter() {
        render_node(child, indent, state)?;
    }
    Ok(())
}

fn render_node(node: &Handle, indent: usize, state: &mut RenderState) -> Result<(), OracleError> {
    state.node_count = state.node_count.saturating_add(1);
    if state.node_count > state.max_nodes {
        return Err(OracleError {
            failure_class: "node-limit-exceeded",
            message: "DOM node limit exceeded.".to_string(),
            node_count: state.node_count,
        });
    }
    if indent > state.max_depth {
        return Err(OracleError {
            failure_class: "depth-limit-exceeded",
            message: "DOM tree depth limit exceeded.".to_string(),
            node_count: state.node_count,
        });
    }

    match &node.data {
        NodeData::Document => render_children(node, indent, state),
        NodeData::Doctype {
            name,
            public_id,
            system_id,
        } => {
            append_output(state, "<!DOCTYPE ")?;
            append_escaped_scalar(state, name.as_ref())?;
            if !public_id.is_empty() || !system_id.is_empty() {
                append_output(state, " \"")?;
                append_escaped_scalar(state, public_id.as_ref())?;
                append_output(state, "\" \"")?;
                append_escaped_scalar(state, system_id.as_ref())?;
                append_output(state, "\"")?;
            }
            append_output(state, ">\n")?;
            Ok(())
        }
        NodeData::Text { contents } => {
            let contents = contents.borrow();
            if !contents.is_empty() {
                push_indent(indent, state)?;
                append_output(state, "\"")?;
                append_escaped_scalar(state, contents.as_ref())?;
                append_output(state, "\"\n")?;
            }
            Ok(())
        }
        NodeData::Comment { contents } => {
            push_indent(indent, state)?;
            append_output(state, "<!-- ")?;
            append_escaped_scalar(state, contents.as_ref())?;
            append_output(state, " -->\n")?;
            Ok(())
        }
        NodeData::ProcessingInstruction { target, contents } => {
            push_indent(indent, state)?;
            append_output(state, "<?")?;
            append_escaped_scalar(state, target.as_ref())?;
            append_output(state, " ")?;
            append_escaped_scalar(state, contents.as_ref())?;
            append_output(state, "?>\n")?;
            Ok(())
        }
        NodeData::Element {
            name,
            attrs,
            template_contents,
            ..
        } => {
            push_indent(indent, state)?;
            append_output(state, "<")?;
            append_escaped_scalar(state, &element_display_name(name))?;
            append_output(state, ">\n")?;

            let attributes = attrs.borrow();
            let mut attribute_order: Vec<(String, usize)> = attributes
                .iter()
                .enumerate()
                .map(|(index, attribute)| {
                    (
                        escaped_scalar(&attribute_display_name(&attribute.name)),
                        index,
                    )
                })
                .collect();
            attribute_order.sort_by(|left, right| compare_display_names(&left.0, &right.0));
            for (render_name, index) in attribute_order {
                let attribute = &attributes[index];
                push_indent(indent + 1, state)?;
                append_output(state, &render_name)?;
                append_output(state, "=\"")?;
                append_escaped_scalar(state, attribute.value.as_ref())?;
                append_output(state, "\"\n")?;
            }

            if name.ns.as_ref() == HTML_NS && name.local.as_ref() == "template" {
                push_indent(indent + 1, state)?;
                append_output(state, "content\n")?;
                if let Some(contents) = template_contents.borrow().as_ref() {
                    render_children(contents, indent + 2, state)?;
                }
                Ok(())
            } else {
                render_children(node, indent + 1, state)
            }
        }
    }
}

fn append_output(state: &mut RenderState, value: &str) -> Result<(), OracleError> {
    let next_length = state.tree.len().checked_add(value.len());
    if next_length.is_none() || next_length.unwrap() > state.max_tree_bytes {
        return Err(OracleError {
            failure_class: "tree-byte-limit-exceeded",
            message: "Rendered tree byte limit exceeded.".to_string(),
            node_count: state.node_count,
        });
    }
    state.tree.push_str(value);
    Ok(())
}

fn append_escaped_scalar(state: &mut RenderState, value: &str) -> Result<(), OracleError> {
    for character in value.chars() {
        match character {
            '\n' => append_output(state, "\\n")?,
            '\r' => append_output(state, "\\r")?,
            '\t' => append_output(state, "\\t")?,
            '\0' => append_output(state, "\\0")?,
            '\\' => append_output(state, "\\\\")?,
            '"' => append_output(state, "\\\"")?,
            character if (character as u32) < 0x20 || character == '\u{7f}' => {
                append_output(state, &format!("\\x{:02X}", character as u32))?;
            }
            character => {
                let mut encoded = [0; 4];
                append_output(state, character.encode_utf8(&mut encoded))?;
            }
        }
    }
    Ok(())
}

fn element_display_name(name: &QualName) -> String {
    match name.ns.as_ref() {
        HTML_NS => name.local.as_ref().to_ascii_lowercase(),
        SVG_NS => format!("svg {}", name.local),
        MATH_NS => format!("math {}", name.local),
        _ => qualified_name(name),
    }
}

fn attribute_display_name(name: &QualName) -> String {
    match name.ns.as_ref() {
        XLINK_NS => format!("xlink {}", name.local),
        XML_NS => format!("xml {}", name.local),
        XMLNS_NS => format!("xmlns {}", name.local),
        _ => qualified_name(name),
    }
}

fn qualified_name(name: &QualName) -> String {
    match name.prefix.as_ref() {
        Some(prefix) => format!("{prefix}:{}", name.local),
        None => name.local.to_string(),
    }
}

fn compare_display_names(left: &str, right: &str) -> std::cmp::Ordering {
    left.contains(':')
        .cmp(&right.contains(':'))
        .then_with(|| left.contains(' ').cmp(&right.contains(' ')))
        .then_with(|| left.as_bytes().cmp(right.as_bytes()))
}

fn push_indent(indent: usize, state: &mut RenderState) -> Result<(), OracleError> {
    for _ in 0..indent {
        append_output(state, "  ")?;
    }
    Ok(())
}

fn escape_scalar_into(value: &str, output: &mut String) {
    for character in value.chars() {
        match character {
            '\n' => output.push_str("\\n"),
            '\r' => output.push_str("\\r"),
            '\t' => output.push_str("\\t"),
            '\0' => output.push_str("\\0"),
            '\\' => output.push_str("\\\\"),
            '"' => output.push_str("\\\""),
            character if (character as u32) < 0x20 || character == '\u{7f}' => {
                output.push_str(&format!("\\x{:02X}", character as u32));
            }
            character => output.push(character),
        }
    }
}

fn escaped_scalar(value: &str) -> String {
    let mut output = String::with_capacity(value.len());
    escape_scalar_into(value, &mut output);
    output
}

fn is_lowercase_sha256(value: &str) -> bool {
    value.len() == 64
        && value
            .bytes()
            .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
}

fn identity_is_valid(build_identity: &str, cargo_lock_sha256: &str) -> bool {
    is_lowercase_sha256(build_identity) && is_lowercase_sha256(cargo_lock_sha256)
}

fn oracle_json() -> String {
    oracle_json_with_identity(BUILD_IDENTITY, CARGO_LOCK_SHA256)
}

fn oracle_json_with_identity(build_identity: &str, cargo_lock_sha256: &str) -> String {
    let available = identity_is_valid(build_identity, cargo_lock_sha256);
    let build_identity_json = json_string(build_identity);
    let cargo_lock_sha256_json = json_string(cargo_lock_sha256);
    format!(
        "{{\"kind\":\"html5ever-source\",\"available\":{available},\"html5everVersion\":\"{HTML5EVER_VERSION}\",\"html5everChecksum\":\"{HTML5EVER_CHECKSUM}\",\"markup5everRcdomVersion\":\"{RCDOM_VERSION}\",\"markup5everRcdomChecksum\":\"{RCDOM_CHECKSUM}\",\"rustToolchain\":\"{RUST_TOOLCHAIN}\",\"cargoLockSha256\":{cargo_lock_sha256_json},\"buildIdentity\":{build_identity_json}}}"
    )
}

fn print_ok(tree: &str, node_count: usize) {
    println!(
        "{{\"status\":\"ok\",\"oracle\":{},\"tree\":{},\"treeBase64\":\"{}\",\"nodeCount\":{}}}",
        oracle_json(),
        json_string(tree),
        base64(tree.as_bytes()),
        node_count
    );
}

fn print_error(failure_class: &str, message: &str, node_count: usize) {
    println!("{}", error_json(failure_class, message, node_count));
}

fn error_json(failure_class: &str, message: &str, node_count: usize) -> String {
    error_json_with_identity(
        failure_class,
        message,
        node_count,
        BUILD_IDENTITY,
        CARGO_LOCK_SHA256,
    )
}

fn error_json_with_identity(
    failure_class: &str,
    message: &str,
    node_count: usize,
    build_identity: &str,
    cargo_lock_sha256: &str,
) -> String {
    let status = if failure_class == "oracle-unsupported" {
        "unsupported"
    } else {
        "error"
    };
    let oracle = oracle_json_with_identity(build_identity, cargo_lock_sha256);
    if status == "unsupported" {
        format!(
            "{{\"status\":\"unsupported\",\"oracle\":{},\"nodeCount\":{},\"failureClass\":\"oracle-unsupported\",\"unsupported\":{{\"message\":{}}}}}",
            oracle,
            node_count,
            json_string(message)
        )
    } else {
        format!(
            "{{\"status\":\"error\",\"oracle\":{},\"nodeCount\":{},\"failureClass\":{},\"error\":{}}}",
            oracle,
            node_count,
            json_string(failure_class),
            json_string(message)
        )
    }
}

fn json_string(value: &str) -> String {
    let mut output = String::with_capacity(value.len() + 2);
    output.push('"');
    for character in value.chars() {
        match character {
            '"' => output.push_str("\\\""),
            '\\' => output.push_str("\\\\"),
            '\u{08}' => output.push_str("\\b"),
            '\u{0c}' => output.push_str("\\f"),
            '\n' => output.push_str("\\n"),
            '\r' => output.push_str("\\r"),
            '\t' => output.push_str("\\t"),
            character if (character as u32) < 0x20 => {
                output.push_str(&format!("\\u{:04X}", character as u32));
            }
            character => output.push(character),
        }
    }
    output.push('"');
    output
}

fn base64(input: &[u8]) -> String {
    const ALPHABET: &[u8; 64] =
        b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    let mut output = String::with_capacity(input.len().div_ceil(3) * 4);
    for chunk in input.chunks(3) {
        let a = chunk[0];
        let b = *chunk.get(1).unwrap_or(&0);
        let c = *chunk.get(2).unwrap_or(&0);
        output.push(ALPHABET[(a >> 2) as usize] as char);
        output.push(ALPHABET[(((a & 0x03) << 4) | (b >> 4)) as usize] as char);
        output.push(if chunk.len() > 1 {
            ALPHABET[(((b & 0x0f) << 2) | (c >> 6)) as usize] as char
        } else {
            '='
        });
        output.push(if chunk.len() > 2 {
            ALPHABET[(c & 0x3f) as usize] as char
        } else {
            '='
        });
    }
    output
}

#[cfg(test)]
mod tests {
    use super::*;
    use markup5ever_rcdom::Node;
    use std::process::Command;

    fn assert_identity_error_json(build_identity: &str, cargo_lock_sha256: &str) {
        let response = error_json_with_identity(
            "oracle-identity-error",
            "Embedded html5ever build identity is missing or malformed.",
            0,
            build_identity,
            cargo_lock_sha256,
        );
        let parsed = Command::new("php")
            .args([
                "-r",
                r#"
$result = json_decode( $argv[1], true, 512, JSON_THROW_ON_ERROR );
if (
    "error" !== ( $result["status"] ?? null ) ||
    "oracle-identity-error" !== ( $result["failureClass"] ?? null ) ||
    false !== ( $result["oracle"]["available"] ?? null ) ||
    $argv[2] !== ( $result["oracle"]["buildIdentity"] ?? null ) ||
    $argv[3] !== ( $result["oracle"]["cargoLockSha256"] ?? null )
) {
    exit( 2 );
}
"#,
                &response,
                build_identity,
                cargo_lock_sha256,
            ])
            .output()
            .expect("PHP must be available to validate the oracle JSON protocol");
        assert!(
            parsed.status.success(),
            "malformed identity response was not valid fail-closed JSON: {}",
            String::from_utf8_lossy(&parsed.stderr)
        );
    }

    #[test]
    fn malformed_build_identity_is_never_available() {
        let valid = "a".repeat(64);
        for malformed in [
            "",
            "unconfigured",
            "ABCDEF",
            "gggggggggggggggggggggggggggggggggggggggggggggggggggggggggggggggg",
            "bad\"quote",
            "bad\\backslash",
            "bad\nnewline",
            "bad\u{001f}control",
        ] {
            assert!(!identity_is_valid(malformed, &valid));
            assert_identity_error_json(malformed, &valid);
            assert_identity_error_json(&valid, malformed);
        }
        let uppercase = "A".repeat(64);
        assert!(!identity_is_valid(&uppercase, &valid));
        assert_identity_error_json(&uppercase, &valid);
    }

    #[test]
    fn processing_instruction_uses_canonical_tree_format() {
        let node = Node::new(NodeData::ProcessingInstruction {
            target: "wp".into(),
            contents: "data".into(),
        });
        let mut state = RenderState {
            tree: String::new(),
            node_count: 0,
            max_nodes: 10,
            max_depth: 10,
            max_tree_bytes: 100,
        };
        render_node(&node, 1, &mut state).expect("processing instruction should render");
        assert_eq!("  <?wp data?>\n", state.tree);
        assert_eq!(1, state.node_count);
    }
}
