#!/usr/bin/env python3
"""UTF-8 oracle server backed by CPython's codec.

CPython's UTF-8 decoder implements the Unicode "maximal subpart"
replacement recommendation, the same behavior WordPress targets.

Protocol (over stdin/stdout, binary):
  request:  4-byte big-endian length N, then N payload bytes
  response: 1 status byte (0x01 valid, 0x00 invalid),
            4-byte big-endian length M, then M bytes of the
            replacement-character-scrubbed UTF-8 text
"""
import struct
import sys


def read_exact(stream, n):
    chunks = []
    while n > 0:
        chunk = stream.read(n)
        if not chunk:
            return None
        chunks.append(chunk)
        n -= len(chunk)
    return b"".join(chunks)


def main():
    inp = sys.stdin.buffer
    out = sys.stdout.buffer

    while True:
        header = read_exact(inp, 4)
        if header is None:
            return
        (length,) = struct.unpack(">I", header)
        data = read_exact(inp, length)
        if data is None:
            return

        try:
            data.decode("utf-8")
            valid = 1
        except UnicodeDecodeError:
            valid = 0

        scrubbed = data.decode("utf-8", errors="replace").encode("utf-8")
        out.write(bytes([valid]) + struct.pack(">I", len(scrubbed)) + scrubbed)
        out.flush()


if __name__ == "__main__":
    main()
