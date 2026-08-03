<?php

it('redirects the root to the admin panel', function () {
    $this->get('/')->assertRedirect('/admin');
});
