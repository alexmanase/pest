<?php

test('basic', function () {
    expect(true)->toBeTrue();
});

it('has good range performance', function ($item) {
    expect($item)->toEqual($item);
})->with(range(1, 500));
