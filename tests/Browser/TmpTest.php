<?php

it('c: asserts class attr', function () {
    browserLogin();
    visit('/dashboard')->assertAttributeDoesntContain('html', 'class', 'dark');
});
