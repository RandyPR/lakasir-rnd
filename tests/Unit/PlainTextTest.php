<?php

use App\Services\Tenants\PlainText;

test('null, empty and blank input give null', function () {
    expect(PlainText::fromHtml(null))->toBeNull();
    expect(PlainText::fromHtml(''))->toBeNull();
    expect(PlainText::fromHtml("  \n\t "))->toBeNull();
    expect(PlainText::fromHtml('<p> </p>'))->toBeNull();
    expect(PlainText::fromHtml('<br><br>'))->toBeNull();
});

test('plain text passes through unchanged', function () {
    expect(PlainText::fromHtml('tanpa gula'))->toBe('tanpa gula');
});

test('tags are removed and block boundaries become line breaks', function () {
    expect(PlainText::fromHtml('<p>Satu</p><p>Dua</p>'))->toBe("Satu\nDua");
    expect(PlainText::fromHtml('Satu<br>Dua<br/>Tiga'))->toBe("Satu\nDua\nTiga");
    expect(PlainText::fromHtml('<ul><li>a</li><li>b</li></ul>'))->toBe("a\nb");
    expect(PlainText::fromHtml('<div>x</div><h1>y</h1>'))->toBe("x\ny");
    expect(PlainText::fromHtml('<p>Pesanan <strong>cepat</strong> ya</p>'))->toBe('Pesanan cepat ya');
});

test('script and style elements disappear with their content', function () {
    expect(PlainText::fromHtml('a<script>alert(1)</script>b'))->toBe('ab');
    expect(PlainText::fromHtml('a<style>.x{color:red}</style>b'))->toBe('ab');
    expect(PlainText::fromHtml('<SCRIPT type="text/javascript">alert(1)</SCRIPT>ok'))->toBe('ok');
});

test('an unterminated script element drops everything after it', function () {
    expect(PlainText::fromHtml('ok<script>alert(1)'))->toBe('ok');
});

test('entities are decoded to text and not interpreted again', function () {
    expect(PlainText::fromHtml('Es &amp; Teh'))->toBe('Es & Teh');
    expect(PlainText::fromHtml('&quot;pedas&quot; &#39;banget&#39;'))->toBe('"pedas" \'banget\'');
    // Encoded markup is text for the cashier, never a tag: it is only echoed with {{ }} or printed.
    expect(PlainText::fromHtml('&lt;b&gt;tebal&lt;/b&gt;'))->toBe('<b>tebal</b>');
});

test('non breaking spaces and tabs become single spaces', function () {
    expect(PlainText::fromHtml("a&nbsp;&nbsp;b\tc"))->toBe('a b c');
});

test('control characters never survive, including ones produced by entities', function () {
    expect(PlainText::fromHtml("a\x1b[0mb\x07c\x00d"))->toBe('a[0mbcd');
    expect(PlainText::fromHtml("a\x1d\x56\x00b"))->toBe('aVb');
    // Whatever an entity decodes to, no control character is left in the result.
    expect(PlainText::fromHtml('x&#27;y&#9;z&#0;w'))->not->toMatch('/[\x00-\x08\x0B-\x1F\x7F]/');
    expect(PlainText::fromHtml('x&#9;y'))->toBe('x y');
});

test('line endings are normalised and blank runs collapsed', function () {
    expect(PlainText::fromHtml("a\r\nb\rc"))->toBe("a\nb\nc");
    expect(PlainText::fromHtml("a\n\n\n\n\nb"))->toBe("a\n\nb");
    expect(PlainText::fromHtml("   a    b   "))->toBe('a b');
});

test('the result is cut to the maximum length', function () {
    $long = str_repeat('abcde ', 400);

    expect(mb_strlen(PlainText::fromHtml($long)))->toBeLessThanOrEqual(1000);
    expect(mb_strlen(PlainText::fromHtml($long, 10)))->toBeLessThanOrEqual(10);
    expect(PlainText::fromHtml('abcdefghij', 4))->toBe('abcd');
});

test('a maximum of zero or less means no cut', function () {
    $long = str_repeat('x', 1500);

    expect(mb_strlen(PlainText::fromHtml($long, 0)))->toBe(1500);
});

test('multibyte text is cut on characters, not bytes', function () {
    expect(PlainText::fromHtml('こんにちは世界', 3))->toBe('こんに');
});

test('invalid utf-8 does not break the result', function () {
    $result = PlainText::fromHtml("ok \xC3\x28 end");

    expect($result)->toBeString();
    expect(mb_check_encoding($result, 'UTF-8'))->toBeTrue();
});
