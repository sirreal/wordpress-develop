#!/usr/bin/env node
/**
 * UTF-8 oracle server backed by the WHATWG TextDecoder.
 *
 * The WHATWG Encoding Standard's UTF-8 decoder implements the Unicode
 * "maximal subpart" replacement recommendation, the same behavior
 * WordPress targets. `ignoreBOM: true` is required: without it the
 * decoder silently strips a leading U+FEFF, which is not part of
 * UTF-8 validation semantics.
 *
 * Protocol (over stdin/stdout, binary):
 *   request:  4-byte big-endian length N, then N payload bytes
 *   response: 1 status byte (0x01 valid, 0x00 invalid),
 *             4-byte big-endian length M, then M bytes of the
 *             replacement-character-scrubbed UTF-8 text
 */
const strict = () => new TextDecoder('utf-8', { fatal: true, ignoreBOM: true });
const lossy = new TextDecoder('utf-8', { ignoreBOM: true });
const encoder = new TextEncoder();

let buffer = Buffer.alloc(0);

process.stdin.on('data', (chunk) => {
	buffer = Buffer.concat([buffer, chunk]);

	for (;;) {
		if (buffer.length < 4) {
			return;
		}

		const length = buffer.readUInt32BE(0);
		if (buffer.length < 4 + length) {
			return;
		}

		const payload = buffer.subarray(4, 4 + length);
		buffer = buffer.subarray(4 + length);

		let valid = 1;
		try {
			strict().decode(payload);
		} catch {
			valid = 0;
		}

		const scrubbed = Buffer.from(encoder.encode(lossy.decode(payload)));
		const header = Buffer.alloc(5);
		header.writeUInt8(valid, 0);
		header.writeUInt32BE(scrubbed.length, 1);
		process.stdout.write(Buffer.concat([header, scrubbed]));
	}
});

process.stdin.on('end', () => process.exit(0));
