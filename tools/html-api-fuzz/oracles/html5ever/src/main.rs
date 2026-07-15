use html5ever::tendril::TendrilSink;
use html5ever::{parse_document, parse_fragment, QualName};
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
    help: bool,
    version: bool,
}

struct RenderState {
    tree: String,
    node_count: usize,
    max_nodes: usize,
}

struct OracleError {
    failure_class: &'static str,
    message: String,
    node_count: usize,
}

fn main() -> ExitCode {
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
            ExitCode::from(2)
        }
    }
}

fn parse_args(args: impl Iterator<Item = String>) -> Result<Options, String> {
    let mut options = Options {
        max_nodes: 3000,
        ..Options::default()
    };
    let mut args = args.peekable();

    while let Some(arg) = args.next() {
        match arg.as_str() {
            "--help" | "-h" => {
                options.help = true;
                return Ok(options);
            }
            "--version" => {
                options.version = true;
                return Ok(options);
            }
            "--mode" => options.mode = Some(next_value(&mut args, "--mode")?),
            "--context" => options.context = Some(next_value(&mut args, "--context")?),
            "--input" => options.input = Some(next_value(&mut args, "--input")?),
            "--max-nodes" => {
                let value = next_value(&mut args, "--max-nodes")?;
                options.max_nodes = value.parse::<usize>().map_err(|_| {
                    "Expected --max-nodes to be a positive integer.".to_string()
                })?;
                if options.max_nodes == 0 {
                    return Err("Expected --max-nodes to be a positive integer.".to_string());
                }
            }
            _ => return Err(format!("Unknown option: {arg}")),
        }
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

fn next_value(
    args: &mut std::iter::Peekable<impl Iterator<Item = String>>,
    option: &str,
) -> Result<String, String> {
    args.next()
        .ok_or_else(|| format!("Missing value for {option}."))
}

fn print_usage() {
    println!(
        "Usage: html5ever-tree-oracle --mode full-document|fragment-* --input PATH [--context TAG] [--max-nodes N]"
    );
}

fn render(options: &Options, input: &[u8]) -> Result<(String, usize), OracleError> {
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
    };
    if is_fragment {
        render_fragment_children(&dom, &mut state)?;
    } else {
        render_children(&dom.document, 0, &mut state)?;
    }
    state.tree.push('\n');
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
    parse_document(RcDom::default(), Default::default())
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
        Default::default(),
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
    state.node_count += 1;
    if state.node_count > state.max_nodes {
        return Err(OracleError {
            failure_class: "node-limit-exceeded",
            message: "DOM node limit exceeded.".to_string(),
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
            state.tree.push_str("<!DOCTYPE ");
            escape_scalar_into(name.as_ref(), &mut state.tree);
            if !public_id.is_empty() || !system_id.is_empty() {
                state.tree.push_str(" \"");
                escape_scalar_into(public_id.as_ref(), &mut state.tree);
                state.tree.push_str("\" \"");
                escape_scalar_into(system_id.as_ref(), &mut state.tree);
                state.tree.push('"');
            }
            state.tree.push_str(">\n");
            Ok(())
        }
        NodeData::Text { contents } => {
            let contents = contents.borrow();
            if !contents.is_empty() {
                push_indent(indent, &mut state.tree);
                state.tree.push('"');
                escape_scalar_into(contents.as_ref(), &mut state.tree);
                state.tree.push_str("\"\n");
            }
            Ok(())
        }
        NodeData::Comment { contents } => {
            push_indent(indent, &mut state.tree);
            state.tree.push_str("<!-- ");
            escape_scalar_into(contents.as_ref(), &mut state.tree);
            state.tree.push_str(" -->\n");
            Ok(())
        }
        NodeData::Element {
            name,
            attrs,
            template_contents,
            ..
        } => {
            push_indent(indent, &mut state.tree);
            state.tree.push('<');
            escape_scalar_into(&element_display_name(name), &mut state.tree);
            state.tree.push_str(">\n");

            let mut attributes: Vec<(String, String)> = attrs
                .borrow()
                .iter()
                .map(|attribute| {
                    (
                        escaped_scalar(&attribute_display_name(&attribute.name)),
                        attribute.value.to_string(),
                    )
                })
                .collect();
            attributes.sort_by(|left, right| compare_display_names(&left.0, &right.0));
            for (name, value) in attributes {
                push_indent(indent + 1, &mut state.tree);
                state.tree.push_str(&name);
                state.tree.push_str("=\"");
                escape_scalar_into(&value, &mut state.tree);
                state.tree.push_str("\"\n");
            }

            if name.ns.as_ref() == HTML_NS && name.local.as_ref() == "template" {
                push_indent(indent + 1, &mut state.tree);
                state.tree.push_str("content\n");
                if let Some(contents) = template_contents.borrow().as_ref() {
                    render_children(contents, indent + 2, state)?;
                }
                Ok(())
            } else {
                render_children(node, indent + 1, state)
            }
        }
        _ => Ok(()),
    }
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

fn push_indent(indent: usize, output: &mut String) {
    for _ in 0..indent {
        output.push_str("  ");
    }
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

fn oracle_json() -> String {
    format!(
        "{{\"kind\":\"html5ever-source\",\"html5everVersion\":\"{HTML5EVER_VERSION}\",\"html5everChecksum\":\"{HTML5EVER_CHECKSUM}\",\"markup5everRcdomVersion\":\"{RCDOM_VERSION}\",\"markup5everRcdomChecksum\":\"{RCDOM_CHECKSUM}\",\"rustToolchain\":\"{RUST_TOOLCHAIN}\"}}"
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
    let status = if failure_class == "oracle-unsupported" {
        "unsupported"
    } else {
        "error"
    };
    if status == "unsupported" {
        println!(
            "{{\"status\":\"unsupported\",\"oracle\":{},\"nodeCount\":{},\"failureClass\":\"oracle-unsupported\",\"unsupported\":{{\"message\":{}}}}}",
            oracle_json(),
            node_count,
            json_string(message)
        );
    } else {
        println!(
            "{{\"status\":\"error\",\"oracle\":{},\"nodeCount\":{},\"failureClass\":{},\"error\":{}}}",
            oracle_json(),
            node_count,
            json_string(failure_class),
            json_string(message)
        );
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
