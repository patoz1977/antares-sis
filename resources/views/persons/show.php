<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$optional = static fn (mixed $value): string => $value === null || $value === '' ? 'Not provided' : (string) $value;
?>
<h1>Person details</h1>

<?php if (is_string($successMessage ?? null) && $successMessage !== ''): ?>
<p><?= $escape($successMessage) ?></p>
<?php endif; ?>

<dl>
    <dt>ID</dt><dd><?= $escape($person->id) ?></dd>
    <dt>First name</dt><dd><?= $escape($person->firstName) ?></dd>
    <dt>Middle name</dt><dd><?= $escape($optional($person->middleName)) ?></dd>
    <dt>First surname</dt><dd><?= $escape($person->firstSurname) ?></dd>
    <dt>Second surname</dt><dd><?= $escape($optional($person->secondSurname)) ?></dd>
    <dt>Document type ID</dt><dd><?= $escape($optional($person->documentTypeId)) ?></dd>
    <dt>Document number</dt><dd><?= $escape($optional($person->documentNumber)) ?></dd>
    <dt>Birth date</dt><dd><?= $escape($person->birthDate->format('Y-m-d')) ?></dd>
    <dt>Sex ID</dt><dd><?= $escape($person->sexId) ?></dd>
    <dt>Marital status ID</dt><dd><?= $escape($optional($person->maritalStatusId)) ?></dd>
    <dt>Education level ID</dt><dd><?= $escape($optional($person->educationLevelId)) ?></dd>
    <dt>Email</dt><dd><?= $escape($optional($person->email)) ?></dd>
    <dt>Mobile phone</dt><dd><?= $escape($optional($person->mobilePhone)) ?></dd>
    <dt>Landline phone</dt><dd><?= $escape($optional($person->landlinePhone)) ?></dd>
    <dt>Status</dt><dd><?= $escape($person->status->value) ?></dd>
</dl>

<p><a href="/persons/edit?id=<?= $escape($person->id) ?>">Edit Person</a></p>
<p><a href="/persons">Back to Persons</a></p>
