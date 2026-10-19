<?php

/*
 * Spec §9 (i18n) and §4.1: switching to Urdu must flip <html dir> to rtl, swap the dictionary and
 * mirror the sidebar to the right; the theme toggle must put `dark` on <html>. Both are pure
 * client-side behaviour, so only a browser can prove them.
 *
 * `<html>` is addressed as `:root`, never as `'html'`. Pest's browser plugin guesses what a selector means
 * (Pest\Browser\Support\Selector::isExplicit): a bare tag name carries no CSS punctuation, so it is taken for
 * *text* and looked up with `getByText('html')`, which matches nothing — and the attribute read that follows
 * then waits for that element with no timeout, hanging the whole suite forever on the first assertion.
 * `:root` contains a colon, so it is passed through as CSS and resolves to the same element.
 */

it('switches to Urdu with a right-to-left, mirrored layout and back to English', function () {
    browserLogin();

    $page = visit('/dashboard');

    $page->assertAttribute(':root', 'dir', 'ltr')
        ->assertPresent('[data-slot="sidebar"][data-side="left"]')
        ->click('[aria-label="'.__('app.language').'"]')
        ->click(trans('app.name', [], 'ur') === '' ? 'اردو' : 'اردو')
        ->assertAttribute(':root', 'dir', 'rtl')
        ->assertAttribute(':root', 'lang', 'ur')
        ->assertSee(trans('app.nav.dashboard', [], 'ur'))
        ->assertPresent('[data-slot="sidebar"][data-side="right"]')
        ->assertNoJavaScriptErrors();

    $page->click('[aria-label="'.trans('app.language', [], 'ur').'"]')
        ->click('English')
        ->assertAttribute(':root', 'dir', 'ltr')
        ->assertSee(__('app.nav.dashboard'))
        ->assertNoJavaScriptErrors();
});

it('toggles the theme between light and dark', function () {
    browserLogin();

    $page = visit('/dashboard');

    $page->assertAttributeDoesntContain(':root', 'class', 'dark')
        ->click('[aria-label="'.__('app.theme').'"]')
        ->assertAttributeContains(':root', 'class', 'dark')
        ->click('[aria-label="'.__('app.theme').'"]')
        ->assertAttributeDoesntContain(':root', 'class', 'dark')
        ->assertNoJavaScriptErrors();
});
