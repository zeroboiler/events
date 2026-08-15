<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

describe('SubscriptionBuilder URL validation', function () {
    it('rejects ftp:// scheme', function () {
        $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
        $builder->on('test.event')->to('ftp://evil.com/steal');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP or HTTPS');

        $builder->save();
    });

    it('rejects file:// scheme', function () {
        $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
        $builder->on('test.event')->to('file:///etc/passwd');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP or HTTPS');

        $builder->save();
    });

    it('rejects mailto: scheme', function () {
        $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
        $builder->on('test.event')->to('mailto:admin@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP or HTTPS');

        $builder->save();
    });

    it('accepts https:// scheme', function () {
        $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
        $builder->on('test.event')->to('https://partner.com/webhooks');

        $subscription = $builder->save();

        expect($subscription)->toBeInstanceOf(\ZeroBoiler\Events\Models\Subscription::class);
        expect($subscription->url)->toBe('https://partner.com/webhooks');
    });

    it('accepts http:// scheme', function () {
        $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
        $builder->on('test.event')->to('http://localhost:8080/hooks');

        $subscription = $builder->save();

        expect($subscription)->toBeInstanceOf(\ZeroBoiler\Events\Models\Subscription::class);
        expect($subscription->url)->toBe('http://localhost:8080/hooks');
    });

    it('rejects completely invalid URL', function () {
        $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
        $builder->on('test.event')->to('not-a-valid-url');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('valid URL');

        $builder->save();
    });

    it('rejects empty URL', function () {
        $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
        $builder->on('test.event')->to('');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('required');

        $builder->save();
    });

    it('rejects javascript: scheme', function () {
        $builder = app(\ZeroBoiler\Events\SubscriptionBuilder::class);
        $builder->on('test.event')->to('javascript:alert(1)');

        // javascript: is not a valid URL per filter_var, so it fails the URL validation first
        $this->expectException(\InvalidArgumentException::class);

        $builder->save();
    });
});
