<?php

/*
 * Spec §9 (i18n) and §4.1: switching to Urdu must flip <html dir> to rtl, swap the dictionary and
 * mirror the sidebar to the right; the theme toggle must put `dark` on <html>. Both are pure
 * client-side behaviour, so only a browser can prove them.
 */

it('switches to Urdu with a right-to-left, mirrored layout and back to English', function () {
    browserLogin();

    $page = visit('/dashboard');

    $page->assertAttribute('html', 'dir', 'ltr')
        ->assertPresent('[data-slot="sidebar"][data-side="left"]')
        ->click('[aria-label="'.__('app.language').'"]')
        ->click(trans('app.name', [], 'ur') === '' ? 'اردو' : 'اردو')
        ->assertAttribute('html', 'dir', 'rtl')
        ->assertAttribute('html', 'lang', 'ur')
        ->assertSee(trans('app.nav.dashboard', [], 'ur'))
        ->assertPresent('[data-slot="sidebar"][data-side="right"]')
        ->assertNoJavaScriptErrors();

    $page->click('[aria-label="'.trans('app.language', [], 'ur').'"]')
        ->click('English')
        ->assertAttribute('html', 'dir', 'ltr')
        ->assertSee(__('app.nav.dashboard'))
        ->assertNoJavaScriptErrors();
});

it('toggles the theme between light and dark', function () {
    browserLogin();

    $page = visit('/dashboard');

    $page->assertAttributeDoesntContain('html', 'class', 'dark')
        ->click('[aria-label="'.__('app.theme').'"]')
        ->assertAttributeContains('html', 'class', 'dark')
        ->click('[aria-label="'.__('app.theme').'"]')
        ->assertAttributeDoesntContain('html', 'class', 'dark')
        ->assertNoJavaScriptErrors();
});
