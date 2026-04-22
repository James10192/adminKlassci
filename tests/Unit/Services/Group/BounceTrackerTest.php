<?php

use App\Services\Group\BounceTracker;

beforeEach(function () {
    config()->set('group_portal.bounce_auto_disable_enabled', true);
});

it('parses 5xx SMTP code from a Symfony TransportException message', function () {
    $tracker = new BounceTracker(threshold: 3);

    $msg = 'Expected response code "250" but got code "550", with message "550 5.1.1 User unknown"';

    expect($tracker->parseSmtpCode($msg))->toBe('550');
});

it('parses 4xx SMTP code for soft bounces', function () {
    $tracker = new BounceTracker(threshold: 3);

    $msg = 'Connection lost after server response: "421 4.7.0 Temporary rate limit"';

    expect($tracker->parseSmtpCode($msg))->toBe('421');
});

it('returns null when no SMTP code is present', function () {
    $tracker = new BounceTracker(threshold: 3);

    $msg = 'Network unreachable: could not resolve mail.example.com';

    expect($tracker->parseSmtpCode($msg))->toBeNull();
});

it('ignores digits outside the 4xx-5xx range (TLS versions, ports, timestamps)', function () {
    $tracker = new BounceTracker(threshold: 3);

    // Port 587, TLS 1.2 — no 4xx/5xx code in this noise.
    $msg = 'TLS 1.2 handshake failure on port 587 after 200ms';

    expect($tracker->parseSmtpCode($msg))->toBeNull();
});

it('prefers the actual server response over the expected-code wrapper', function () {
    $tracker = new BounceTracker(threshold: 3);

    // This is the common Symfony Mailer shape: "Expected 250 but got 550".
    // We want 550 (actual), not 250 (expected) — the regex excludes 2xx/3xx.
    $msg = 'Expected response code "250" but got "550"';

    expect($tracker->parseSmtpCode($msg))->toBe('550');
});
