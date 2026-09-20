# Hunch

Typed decisions with estimated probabilities for the [Laravel AI SDK](https://github.com/laravel/ai).

Ask a model the same typed questions about a piece of text several times, count the answers, and get back a typed result with a vote share for each one. You can also measure, later, how often those answers were right.

> Early release. The API may change before 0.1.

- [Quick start](#quick-start)
- [Installation](#installation)
- [Asking questions](#asking-questions)
- [Probabilities are estimates](#probabilities-are-estimates)
- [Recording, labels and measured accuracy](#recording-labels-and-measured-accuracy)
- [More examples](#more-examples)
- [Provider compatibility](#provider-compatibility)

## Quick start

```php
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\Questions\Choice;

$result = Hunch::of('The jumper arrived with a hole in the sleeve. I would like my money back please.')
    ->question('intent', new Choice('What does the customer want?', [
        'refund' => 'Money back for an order',
        'exchange' => 'A different size, colour or item',
        'other' => 'Anything else',
    ]))
    ->using('anthropic', 'claude-sonnet-5')
    ->sampling(5)
    ->classify();

$intent = $result->answer('intent');

$intent->choice;      // 'refund'
$intent->confidence;  // 0.8 — four of the five samples said refund
$intent->votes;       // ['refund' => 4, 'exchange' => 1, 'other' => 0]
```

Two things to know before you build on this. A `confidence` of 0.8 means four of five samples agreed; it does not mean [the answer is right 80% of the time](#probabilities-are-estimates). And none of it needs a database. [You need one](#do-you-need-a-database) only to measure how often the answers are right.

## Installation

Hunch needs PHP 8.3 or later, Laravel 13, and [`laravel/ai`](https://github.com/laravel/ai) with a provider configured and its API key in your environment.

It is not on Packagist, so Composer needs to be told where the package is.

### From the repository

Add the repository, then require the release:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ninthspace/hunch.git" }
    ],
    "require": {
        "ninthspace/hunch": "^0.0.1"
    }
}
```

```bash
composer require ninthspace/hunch:^0.0.1
```

To track the default branch instead of a release, require `dev-main`.

### Working on Hunch alongside an application

To develop the package and an application side by side, point Composer at the directory instead. A path repository symlinks by default, so edits show up in the application immediately:

```json
{
    "repositories": [
        { "type": "path", "url": "../hunch" }
    ],
    "require": {
        "ninthspace/hunch": "@dev"
    }
}
```

### Then, in the application

```bash
php artisan vendor:publish --tag=hunch-config
```

The service provider is discovered automatically. Set a default provider and model in `config/hunch.php`, or name them per classification with `using()`:

```php
// config/hunch.php
'provider' => 'anthropic',
'model' => 'claude-sonnet-5',
```

Persistence is off by default, so there is nothing else to set up: no migrations, no tables, and a classification makes no database queries at all.

### Configuration reference

Every key in `config/hunch.php`, and what it does.

| Key | Default | What it does |
|---|---|---|
| `driver` | `'sampling'` | The driver used when the provider does not name one. The TypeSafe driver is chosen by provider instead — `using('typesafe', …)` — not by this key. |
| `provider` | `null` | The `laravel/ai` provider to prompt. Required unless every classification calls `using()`. |
| `model` | `null` | The model to ask. Passed straight to the SDK, which decides which names are valid. |
| `sampling` | `'fixed:5'` | The default rule: `fixed:N` always takes N samples, `adaptive:MIN-MAX` takes MIN and adds two at a time until the answers settle or MAX is reached. Overridden per classification by `sampling()`. |
| `min_valid_samples` | `3` | How many samples must come back valid for an answer to be given. Below it, `classify()` throws `ClassificationFailed`. It is never scaled down: if the rule can take fewer samples than this, Hunch throws before calling the model. |
| `max_resamples` | `2` | How many extra calls may be spent replacing samples whose output failed the schema, so `sampling` counts samples that could be used. At worst a classification costs the rule's own count plus this many calls. Set it to `0` to replace nothing and take the rule's count once. |
| `timeout` | `20` | Seconds allowed per model call. |
| `retries` | `2` | How many times a rate-limited or overloaded call is retried against the same provider, backing off 500ms, 1s, 2s and so on. Other provider errors are not retried. |
| `failover` | `false` | Whether the SDK may fail over to another provider. Off by default, because a silent switch of provider changes what a calibration cell means. When on, the requested provider leads and the agent's own declared providers follow. |
| `agent` | `ClassifierAgent::class` | The agent class used to prompt. Replace it with a subclass to change temperature, token limits or the provider list; anything that is not a `ClassifierAgent` is refused. |
| `choice.max_options` | `50` | The most options one `Choice` may offer. A count outside 2 to this is rejected when the question is built. |
| `buckets` | `[1.0, 0.8, 0.6]` | The probability bands accuracy is measured within, highest edge first. These defaults give `1.0`, `[0.8, 1.0)`, `[0.6, 0.8)` and `[0, 0.6)`. Edges must descend strictly and sit in (0, 1]; anything else is refused when a bucket is first needed. Changing them changes which cell an answer lands in, so past measurements no longer line up. |
| `calibration.min_n` | `30` | How many labelled classifications a cell needs before `calibration()` reports it. Below that it returns `null`. |
| `persistence` | `false` | Whether classifications are recorded. Off means no queries, no migrations and no tables, and `record()` and `Hunch::label()` throw. See [Do you need a database?](#do-you-need-a-database). |
| `retention_days` | `null` | How long recorded rows are kept before `model:prune` removes them, with their samples. Required when persistence is on; the package refuses to boot without it. Nothing is pruned unless it is a positive number, and labelled classifications are never pruned. |

Every key can be set per environment in the usual way. Some are read when first used rather than at boot — `sampling`, `buckets` and `retention_days` among them — so a bad value surfaces when a classification runs, in a message naming the key.

## Asking questions

One `classify()` call asks every question together, several times, and returns a `Result`:

```php
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\Questions\{Boolean, Choice, Score};

$result = Hunch::of('The jumper arrived with a hole in the sleeve. I would like my money back please.')
    ->context('A support inbox for a clothing shop.')
    ->questions([
        'intent' => new Choice('What does the customer want?', [
            'refund' => 'Money back for an order',
            'exchange' => 'A different size, colour or item',
            'delivery' => 'To find out where an order is, or to change delivery',
            'other' => 'Anything else',
        ]),
        'urgency' => new Score('How urgent is this message?', ['low' => 'It can wait', 'medium' => 'Today or tomorrow', 'high' => 'Within the hour']),
        'complaint' => new Boolean('Is the customer complaining?'),
    ])
    ->using('anthropic', 'claude-sonnet-5')
    ->sampling(5)
    ->classify();
```

Each question type returns its own kind of answer. The three sections below show each pair in full, with the numbers from a five-sample run.

### Choice, and ChoiceAnswer

One option from a list of 2 to 50, given as `key => description`. The key is what you get back; the description is for the model.

```php
$result = Hunch::of('The jumper arrived with a hole in the sleeve. I would like my money back please.')
    ->context('A support inbox for a clothing shop.')
    ->question('intent', new Choice('What does the customer want?', [
        'refund' => 'Money back for an order',
        'exchange' => 'A different size, colour or item',
        'delivery' => 'To find out where an order is, or to change delivery',
        'other' => 'Anything else',
    ]))
    ->sampling(5)
    ->classify();

$intent = $result->answer('intent');
```

With four of the five samples answering `refund` and one `delivery`:

```php
$intent->choice;                    // 'refund' — the option with the most votes
$intent->votes;                     // ['refund' => 4, 'exchange' => 0, 'delivery' => 1, 'other' => 0]
$intent->probabilities;             // ['refund' => 0.8, 'exchange' => 0.0, 'delivery' => 0.2, 'other' => 0.0]
$intent->probabilityOf('delivery'); // 0.2, and throws for an option the question never offered
$intent->confidence;                // 0.8 — the leading option's share
$intent->entropy;                   // 0.36 — 0.0 when unanimous, 1.0 when evenly split
$intent->tied;                      // false — true when the winner had to win a tie
$intent->unanimous();               // false
$intent->bucket;                    // '[0.8, 1.0)' — which band its accuracy is measured in
$intent->applies;                   // true
$intent->calibration();             // null until labelled classifications exist
```

### Boolean, and BooleanAnswer

True or false, with optional descriptions of what each means.

```php
$result = Hunch::of('Nobody has answered me in four days about a missing delivery. This is unacceptable.')
    ->context('A support inbox for a clothing shop.')
    ->question('complaint', new Boolean('Is the customer complaining?', [
        'true' => 'They are unhappy with the product or the service',
        'false' => 'They are asking or telling, without complaint',
    ]))
    ->sampling(5)
    ->classify();

$complaint = $result->answer('complaint');
```

With four samples saying true and one false:

```php
$complaint->probability;   // 0.8 — the share that said true
$complaint->isTrue();      // true, at a 0.5 threshold
$complaint->isTrue(0.9);   // false — your own threshold, if 0.5 is too generous
$complaint->votes;         // ['true' => 4, 'false' => 1]
$complaint->anyTrue();     // true — one "yes" is enough, for questions where it matters
$complaint->unanimous();   // false
$complaint->bucket;        // '[0.8, 1.0)' — built from 0.8, the winning side's share
```

There is no tie rule for a Boolean: a tie is a probability of exactly 0.5.

### Score, and ScoreAnswer

An ordered scale, lowest level first. Levels are keys with descriptions, or a plain list of labels.

```php
$result = Hunch::of('My order says delivered but there is nothing here. I need it before the wedding on Saturday.')
    ->context('A support inbox for a clothing shop.')
    ->question('urgency', new Score('How urgent is this message?', [
        'low' => 'It can wait a few days',
        'medium' => 'It should be answered today or tomorrow',
        'high' => 'It needs an answer within the hour',
    ]))
    ->sampling(5)
    ->classify();

$urgency = $result->answer('urgency');
```

With three samples saying high and two medium:

```php
$urgency->score;           // 1.6 — the mean level index, where low is 0 and high is 2
$urgency->level();         // 'high' — the nearest level to that mean, halves rounding up
$urgency->mode;            // 'high' — the level with the most votes, which can differ from level()
$urgency->votes;           // ['low' => 0, 'medium' => 2, 'high' => 3]
$urgency->probabilities;   // ['low' => 0.0, 'medium' => 0.4, 'high' => 0.6]
$urgency->tied;            // false
$urgency->unanimous();     // false
$urgency->bucket;          // '[0.6, 0.8)' — built from 0.6, the modal level's share
```

`score` and `level()` answer different questions from `mode`. The mean leans toward the middle when samples disagree, so a run split evenly between `low` and `high` scores 1.0 and levels as `medium`, which no sample said. `mode` is the level most samples chose.

Every answer also has `applies` and `calibration()`. The `Result` itself holds `answers`, `samples` (requested, valid, invalid), `usage`, `meta` and `id`.

`answers` is an object rather than an array. Asking for a question that was never asked fails the same way however you reach it, and the states a conditional question can end in have methods of their own:

```php
$result->answers->get('intent');   // the collection
$result->answer('intent');         // the same object, straight off the Result
$result['intent'];                 // the same object again, as a shorthand

$result->answers->has('colour');   // false, rather than a surprise later
$result->answers->keys();          // every question asked, in canonical order
$result->answers->applicable();    // only the answers that stand
$result->answers->unanswered();    // questions too few samples qualified to answer
count($result->answers);           // and it is iterable

foreach ($result->answers->applicable() as $question => $answer) {
    // ...
}
```

All three return the same object, and all three throw for a question the classification never asked.

### Sampling, and what happens when it fails

`sampling(5)` takes five samples. Adaptive sampling takes the minimum, then adds two at a time until the answers settle or the maximum is reached:

```php
->sampling(adaptive: [3, 9])
```

Both counts are counts of usable samples. A sample whose output does not match the schema is recorded, kept out of every vote, and replaced, so `sampling(5)` still gives a five-sample vote when two come back malformed.

Replacement is capped. `max_resamples` is how many calls beyond the rule's own count may be spent on it, two by default:

```php
// fixed:5 with the default cap: 5 calls normally, 7 at the very worst.
// Set hunch.max_resamples to 0 to take five calls and no more, whatever
// comes back.
->sampling(5)
```

On a capable model this rarely happens: the last compatibility run had no
malformed samples in 126. Replacement only runs when a sample fails, so it
costs nothing the rest of the time, and it matters most on a weaker, cheaper or
local model.

Hunch never substitutes a default answer. If the cap runs out with fewer than `min_valid_samples` valid, `classify()` throws `ClassificationFailed`, carrying every sample taken and what they cost:

```php
try {
    $result = Hunch::of('The jumper arrived with a hole in the sleeve.')
        ->question('intent', new Choice('What does the customer want?', [
            'refund' => 'Money back for an order',
            'other' => 'Anything else',
        ]))
        ->classify();
} catch (ClassificationFailed $e) {
    $e->samples;        // every sample taken, valid or not
    $e->validSamples(); // the ones that could be counted
    $e->usage;          // tokens spent before giving up
}
```

Rate limits and provider overloads are retried with backoff against the same provider, `retries` times.

### Conditional questions and ties

Every sample is asked every question, but a question marked `onlyWhen` is
counted only over the samples whose controlling answer matched. A restaurant
inbox, where the date only matters for a booking:

```php
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Result;

function enquiry(string $text): Result
{
    return Hunch::of($text)
        ->context('The inbox of a restaurant.')
        ->questions([
            'intent' => (new Choice('What does the writer want?', [
                'booking' => 'To book a table',
                'other' => 'Anything else',
            ]))->tieBreak('other'),

            'group_date' => (new Choice('Which date do they want?', [
                'saturday' => 'Saturday',
                'sunday' => 'Sunday',
            ]))->onlyWhen('intent', 'booking'),
        ])
        ->using('anthropic', 'claude-sonnet-5')
        ->sampling(5)
        ->classify();
}
```

A conditional answer ends in one of three states.

**The condition was met.** All five samples say this is a booking for Saturday:

```php
$result = enquiry('Can we book a table for six on Saturday evening?');

$result->answer('intent')->choice;       // 'booking'
$result->answer('group_date')->choice;   // 'saturday'
$result->answer('group_date')->applies;  // true
$result->answer('group_date')->votes;    // ['saturday' => 5, 'sunday' => 0]
$result->answers->applicable()->keys();  // ['group_date', 'intent']
```

**The condition was not met, but enough samples qualified to answer anyway.**
Two samples read this as a booking, three did not:

```php
$result = enquiry('Do you do gift vouchers? We might book for Saturday later in the year.');

$result->answer('intent')->choice;       // 'other' — three of five
$result->answer('group_date')->choice;   // 'saturday' — counted over the two booking samples
$result->answer('group_date')->applies;  // false — the winning intent was not a booking
$result->answers->applicable()->keys();  // ['intent'] — group_date is dropped
$result->answers->unanswered();          // [] — it has an answer, it just does not apply
```

The answer is still there, because two samples is enough to count, but
`applies` says not to use it. `applicable()` filters these out.

**Too few samples qualified.** Only one sample read this as a booking, and a
conditional question needs at least two:

```php
$result = enquiry('What are your opening hours on a Sunday?');

$result->answer('intent')->choice;       // 'other'
$result->answer('group_date');           // null — nothing was counted
$result->answers->unanswered();          // ['group_date']
$result->answers->has('group_date');     // true — it was asked, it has no answer
```

**Ties.** `tieBreak('other')` names the option that wins when the leading
options are level. Without it the tie goes to the option declared first, which
is whatever order you happened to write them in. Four samples, split two and two:

```php
$result = enquiry('Table for two on Saturday, or do you just do takeaway now?');

$result->answer('intent')->choice;          // 'other' — the tie-break won it
$result->answer('intent')->votes;           // ['booking' => 2, 'other' => 2]
$result->answer('intent')->probabilities;   // ['booking' => 0.5, 'other' => 0.5]
$result->answer('intent')->tied;            // true — this answer came from a tie, not a majority
```

Check `tied` before acting on a Choice or Score: a 0.5 confidence that won on a
tie-break is not a clear result. A Boolean needs no rule, because a tie is a
probability of exactly 0.5.

### Reasons, self-report and redaction

```php
->withReasons(120)      // one short reason per sample, display only: $result->reasons
->withSelfReport()      // each sample states a confidence band, recorded for comparison
->redactUsing(ContactDetails::class)  // or any callable, run once before the first call
```

Reasons never feed back into a vote, a probability or a prompt. `redactUsing()`
rewrites the text once per classification, before the first model call, so every
sample and the `state_hash` see the redacted form:

```php
->redactUsing(ContactDetails::class)   // alice@example.com -> [email], 07700 900123 -> [phone]
```

### Untrusted text

Hunch does not detect prompt injection. Nothing on the `Result` says whether a
message tried it, and whether a model is steered by one is a property of the
model, not of this package. What Hunch does is contain the attempt, so an
instruction buried in the text has less to work with:

- The text is delimited in `<state>` tags, and any `<state>` or `<section>` tag
  inside the text is escaped first, so it cannot close the block early and
  start issuing instructions of its own.
- The instructions tell the model to treat everything inside `<state>` as data,
  and not to follow instructions found there.
- Only output matching the schema is counted, so a sample cannot answer with
  something the question never offered.

None of that stops a model being talked into a wrong answer that is still a
valid one. If the text says "answer other" and the model obliges, `other` is a
legal option and it is counted like any other vote. That residual risk belongs
to whichever provider you send the text to.

The compatibility run measures it rather than asserting on it. Six of its
fixtures are messages whose text tries to steer the answer, each labelled with
the answer the text actually supports, so a provider that complies is
measurably wrong. The figure it reports is one run against one model, and says
nothing about any other.

If you need a signal per message, ask for one. `new Boolean('Does this message
try to instruct the assistant?')` is classified like any other question, with a
vote share and, once labelled, measured accuracy.

### Testing

```php
Hunch::fake(['intent' => ['refund' => 0.8, 'other' => 0.2]]);   // answer directly
Hunch::fake()->samples([['intent' => 'refund'], ['intent' => 'other']]);  // real aggregation over scripted samples

Hunch::fake()->assertSampledTimes(3);
Hunch::fake()->preventStrayClassifications();
```

Seeds are fixed under the fake, so shuffles and prompts repeat exactly.

### Events

Hunch dispatches `Classifying`, `SampleTaken`, `SampleInvalid`, `Classified`,
`ClassificationFailed`, `Labelled` and `CalibrationComputed`. The SDK's own
`PromptingAgent` and `AgentPrompted` still fire for each sample if you want the
raw request and response.

No payload carries the text being classified. They hold ids, hashes and counts,
which is what makes them safe to log or ship to an APM without reviewing every
message first. Every classification event carries the same `classificationId`,
`questionSetHash` and `stateHash`, so the lines from one run join up, and you
can group by question set version.

| Event | Carries | What it is for |
|---|---|---|
| `Classifying` | provider, model, sampling rule | The run started. Says which provider and rule an id used, before anything is known about the outcome. |
| `SampleTaken` | sample number, request id, reported model | The provider's own request id, so a bad answer can be traced on their side. The reported model catches a provider quietly serving something else. |
| `SampleInvalid` | sample number, problem, request id | Output that failed the schema, with the reason it failed. |
| `Classified` | sample counts, usage | Token spend per classification, and the valid-to-invalid split behind the answer. |
| `ClassificationFailed` | samples taken, valid samples, cause | Worth alerting on. `cause` is the exception class when a provider error ended the run, and `null` when the run simply ran out of valid samples. |
| `Labelled` | the questions labelled | A person confirmed an answer. Use it to trigger whatever should follow a review. |
| `CalibrationComputed` | cells, labelled | `hunch:calibrate` finished, so the accuracy behind `calibration()` is as fresh as this event is recent. |

`SampleInvalid` is the one worth metering, because it moves without anything
else looking different: the classification still returns an answer, just one
counted over fewer samples. A rising rate is the earliest sign that a prompt, a
model or a token limit has changed. An output ceiling set too low is a common
cause, truncating valid answers into invalid samples.

```php
Event::listen(SampleInvalid::class, function (SampleInvalid $event) {
    Log::warning('hunch sample invalid', [
        'classification' => $event->classificationId,
        'question_set' => $event->questionSetHash,
        'sample' => $event->sampleNumber,
        'problem' => $event->problem,
        'request_id' => $event->requestId,
    ]);
});
```

## Probabilities are estimates

Hunch asks the model the same questions several times and counts the answers. Every `probability`, `probabilities` and `confidence` value is a vote-share estimate, not a calibrated probability. A `confidence` of 0.8 means 80% of the samples agreed, not that the answer is right 80% of the time.

### Why several samples

One call gives you an answer and nothing else. A model will pick `refund`
whether the message plainly asks for one or barely suggests it, and the reply
looks the same either way. The judgement of how close the call was is made
somewhere inside the model and then discarded.

Sampling more than once recovers some of it. Hunch prompts at temperature 1.0,
so each sample is a fresh draw from the model's own distribution over the
answers, and counting how often each answer comes back estimates that
distribution. Four of five agreeing says the model was close to settled. Three
against two says it was not, and that is a useful thing to know before acting.

Each sample also shuffles the order of the questions, of a Choice's options and
of a Score's levels, under a seed derived from the classification id and the
sample number. Agreement that survives reordering is agreement about the text,
rather than about the position an option happened to occupy in the list.

### Why not ask the model how sure it is

You can: `withSelfReport()` asks each sample for a confidence band. Hunch
records it for comparison and does not use it as the probability. A model's
own account of its certainty is not a dependable guide to whether it is right,
and it tends to sit high whatever the question.

`hunch:calibrate` measures the stated bands alongside the vote shares, so an
application can see which of the two better predicts a correct answer on its
own data instead of taking either on trust.

### Why not token probabilities

Log-probabilities would give the distribution directly, from one call rather
than five. Two things make that a poor default here: not every provider exposes
them, and structured output usually hides the token that carried the answer, so
what comes back describes the JSON envelope rather than the choice.
Multi-sampling needs nothing beyond structured output, which is the only thing
the sampling driver asks of a provider. A logprob driver is deferred rather
than ruled out.

### What a vote share does not tell you

Consistency is not correctness. A model can return the same wrong answer five
times out of five, and a vote share of 1.0 then says only that it was not in
two minds.

How often an answer is right is measured separately, against answers a person has confirmed. `$answer->calibration()` returns that measured accuracy for the answer's bucket once enough labelled classifications exist, and `null` until then.

## Recording, labels and measured accuracy

### Do you need a database?

No, not to classify. Persistence is off by default, and with it off there are
no migrations, no tables, and a classification makes no database queries at
all. It works in an application with no database configured.

You need one only for the things that depend on remembering what was asked:

| Without a database | With persistence on |
|---|---|
| `classify()` returns answers with vote-share estimates | The same, plus every classification and sample is recorded |
| `$answer->calibration()` returns `null` | It returns measured accuracy once a cell has enough labels |
| `record()` and `Hunch::label()` throw | People can confirm answers, and those labels are the ground truth |
| `hunch:calibrate` and `hunch:eval` have nothing to read | They measure accuracy per cell, and compare drivers |

Turn it on when you want to find out how often the answers are right.
Measuring that needs somewhere to keep the classification and the human label,
so the two can be joined later.

It stores question sets, classifications, their samples, labels and calibration
cells. **The text being classified is never stored** — only `state_hash`, a
SHA-256 of what was sent. Model-written reasons are stored only when
`withReasons()` asked for them, with any passage quoted from the text scrubbed
out first.

Installing Hunch adds no migrations to your application. They are offered for
publishing and never loaded, so `php artisan migrate` in an application that has
not published them has nothing of Hunch's to run.

### Turning persistence on

```php
// config/hunch.php
'persistence' => true,
'retention_days' => 90,
```

```bash
php artisan vendor:publish --tag=hunch-migrations
php artisan migrate
```

Pruning uses Laravel's `Prunable`, and `model:prune` only discovers an application's own models, so schedule Hunch's by name:

```php
Schedule::command('model:prune', ['--model' => [\Ninthspace\Hunch\Models\Classification::class]])->daily();
Schedule::command('hunch:calibrate')->daily();
```

Labelled classifications are never pruned, and nothing is pruned at all until `retention_days` is a positive number.

### Recording and labelling

Record a classification against one of your models, then confirm answers as people review them:

```php
$result = Hunch::of('The jumper arrived with a hole in the sleeve. I would like my money back please.')
    ->question('intent', new Choice('What does the customer want?', [
        'refund' => 'Money back for an order',
        'other' => 'Anything else',
    ]))
    ->record($enquiry)   // any Eloquent model: the classification is recorded against it
    ->classify();

Hunch::label($result->id, ['intent' => 'refund'], by: $reviewer);
```

A later label for the same question supersedes the earlier one, and both are kept.

### Measuring

```bash
php artisan hunch:calibrate
php artisan hunch:eval "v1:…" --driver=sampling:anthropic:claude-sonnet-5
```

`hunch:calibrate` measures each answer cell — question set, driver, provider, model, sampling rule, question, answer and bucket — against current labels. `$answer->calibration()` then returns `{n, accuracy, lowerBound}` once a cell has `calibration.min_n` labels behind it, and `null` before that.

`hunch:eval` re-classifies labelled items with each driver named and compares them, so two models can be judged on the same items. It asks each item's subject for its text through `Ninthspace\Hunch\Contracts\ProvidesHunchState`, because Hunch never stored it:

```php
class Enquiry extends Model implements ProvidesHunchState
{
    public function hunchState(): string
    {
        return $this->body;
    }
}
```

### Adding persistence later

You can add a database later. Nothing about the package changes and nothing
needs reinstalling: publish the migrations, run them, and turn the switch on,
as above.

Two things to note:

- **Recording starts from that moment.** Classifications made before the switch
  was on were never stored, so they cannot be labelled or measured
  retrospectively. `calibration()` stays `null` for a cell until enough newly
  recorded classifications have been labelled.
- **The switch alone is not enough.** If persistence is on but the migrations
  have not been run, `record()`, `Hunch::label()`, `calibration()` and
  `hunch:calibrate` each report which step is missing.

## More examples

### Routing to one of 45 departments

This is one of the fixtures `composer compat` runs against a real provider, so
the answer below is one that run produced rather than an invented one.

A Choice takes up to `hunch.choice.max_options` options, 50 by default. A long option list makes for long instructions, which is where prompt caching helps most: in the run recorded [below](#provider-compatibility), this question set read 48,125 cached input tokens.

```php
$result = Hunch::of('I cannot sign in. The site says my password is wrong and the reset email never comes.')
    ->context('A department store routing desk. Choose the one department that should answer.')
    // $departments is 45 options, 'womenswear' => 'Women\'s clothing' and so on.
    ->question('department', new Choice('Which department should handle this message?', $departments))
    ->sampling(3)
    ->classify();

$result['department']->choice;  // 'accounts'
```

## Provider compatibility

Hunch is checked against real providers by a manual, local run: `composer compat`.
It classifies a public, synthetic fixture set of 42 labelled items and reports how
often a provider's output failed the schema, how often its answers matched the
labels, and what the run cost in tokens.

<!-- compat:results -->
| Provider | Model | Items | Samples | Invalid samples | Accuracy | Input | Cached input | Output |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| anthropic | claude-sonnet-5 | 42 | 126 | 0.0% | 91.1% | 73,338 | 48,125 | 4,393 |

Last run 2026-09-20 by `composer compat`, over 1 provider.
Accuracy is measured over a small synthetic fixture set and is a compatibility check, not a benchmark: see `calibration()` for measured accuracy in an application.
<!-- compat:results:end -->

The run needs `ANTHROPIC_API_KEY` in your environment and calls a real provider, so it costs money. It is never run by `composer test`.

## Development

```bash
composer install
composer test          # the full suite, no network
composer check         # PHPStan at level max, and Pint
composer test:offline  # the suite with outbound network denied by the OS
```

### The docs and .dpm directories

The repository carries two directories that are not part of the package:
`docs/` and `.dpm/`. They hold the planning record for how Hunch was built —
the specification, the epics and their coverage matrices, the retrospectives.

They come from [dpm](https://github.com/ninthspace/claude-code-marketplace), a
Claude Code plugin that keeps planning artefacts as database rows with markdown
as a generated projection. It is the tool I happen to plan with. It is not a
requirement of working on this package and not a recommendation: nothing in
`composer test` or `composer check` reads either directory, no source file
references them, and the code, the tests and this README stand on their own
without them. Ignore both if you are here for the package.

If you do touch them, one thing is worth knowing. `.dpm/dpm.db` is the record
and is ignored by git; `.dpm/dpm.sql` is its text form and is the copy under
version control. Everything under `docs/` is generated from that database and
rewritten wholesale whenever it is regenerated, so an edit made there by hand
is lost the next time it is written.
