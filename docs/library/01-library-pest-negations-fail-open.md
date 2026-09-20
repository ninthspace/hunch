# Pest negations fail open on variadic matchers

**Number**: 01  
**Status**: complete  

**Type**: coding-standards  
**Scope**: do  

## Pass exactly one argument to a negated matcher

Several Pest matchers take variadic arguments. Under a negation they pass when **any** argument is absent, not when all of them are, so an extra argument turns the assertion into one that cannot fail.

Two forms have cost time on this project, each found only because a planted defect was expected to fail and did not.

**An arch rule over several targets needs `->each`:**

```php
expect(['App\Prompts', 'App\Answers'])->not->toUse(Sample::class);        // never fails
expect(['App\Prompts', 'App\Answers'])->each->not->toUse(Sample::class);  // correct
```

Without `each`, the expectation is satisfied as soon as one namespace does not use the class. Note that `toBeReadonly()` and `not->toBeUsed()` over an array *do* hold, so the trap is specific to the matchers that take their own arguments.

**A message passed to a negated `toContain` becomes a second needle:**

```php
expect($source)->not->toContain('complied', 'why this matters');  // never fails
expect($source)->not->toContain('complied');                      // correct
```

The explanatory string is absent from the subject, so the negation is satisfied whatever the real needle does. When a failure needs explaining, collect the offenders and assert the list is empty instead:

```php
expect($offenders)->toBe([], 'compliance is reported, never asserted on');
```

**The rule:** under `not`, give a variadic matcher exactly one argument, and put any explanation in a separate assertion or in the collected value. **The check:** plant the defect the assertion is supposed to catch and watch it fail. Both instances above passed review, passed CI, and were caught only by planting.
