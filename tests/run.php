<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Tests\Support\TestRunner;

$runner = new TestRunner();

require __DIR__ . '/IdentityAccessTest.php';
require __DIR__ . '/PersonDomainTest.php';
require __DIR__ . '/PersonPersistenceTest.php';
require __DIR__ . '/PersonApplicationTest.php';
require __DIR__ . '/PersonDeliveryTest.php';
require __DIR__ . '/RepresentativeDomainTest.php';
require __DIR__ . '/StudentDomainTest.php';
require __DIR__ . '/RepresentativePersistenceTest.php';
require __DIR__ . '/StudentPersistenceTest.php';
require __DIR__ . '/RepresentativeApplicationTest.php';
require __DIR__ . '/StudentApplicationTest.php';
require __DIR__ . '/FamilyMembershipDomainTest.php';
require __DIR__ . '/SchemaBaselineTest.php';

\Tests\registerIdentityAccessTests($runner);
\Tests\registerPersonDomainTests($runner);
\Tests\registerPersonPersistenceTests($runner);
\Tests\registerPersonApplicationTests($runner);
\Tests\registerPersonDeliveryTests($runner);
\Tests\registerRepresentativeDomainTests($runner);
\Tests\registerStudentDomainTests($runner);
\Tests\registerRepresentativePersistenceTests($runner);
\Tests\registerStudentPersistenceTests($runner);
\Tests\registerRepresentativeApplicationTests($runner);
\Tests\registerStudentApplicationTests($runner);
\Tests\registerFamilyMembershipDomainTests($runner);
\Tests\registerSchemaBaselineTests($runner);

ob_start();
try {
    $runner->run();
} finally {
    echo (string) ob_get_clean();
}
