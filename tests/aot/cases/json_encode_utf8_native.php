<?php
// Native \uXXXX escaper parity: 2/3/4-byte UTF-8 (incl. surrogate pairs),
// mixed ASCII+multibyte, rare C0 controls, all short escapes, slash,
// multibyte in KEYS, and an ASCII-prefix string that switches to the slow
// path mid-string.
echo json_encode("привіт"), "\n";
echo json_encode("é"), "\n";
echo json_encode("日本語テキスト"), "\n";
echo json_encode("emoji 🦊🚀 pair"), "\n";
echo json_encode("mixed: ascii → юнікод 漢字 🎉 end"), "\n";
echo json_encode("ctl:\x01\x02\x1f tab:\t nl:\n cr:\r bs:\x08 ff:\x0c"), "\n";
echo json_encode("quote\" back\\ slash/ done"), "\n";
echo json_encode(["ключ" => "значення", "エッジ" => ["深い" => "🦊"]]), "\n";
echo json_encode("long ascii prefix before the very first non-ascii байт"), "\n";
echo json_encode(""), "\n";
echo json_encode("ß"), "\n";
echo json_encode("𝄞 clef"), "\n";
