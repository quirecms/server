<?php

declare(strict_types=1);

namespace Quire\Server\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Quire\Server\Registry\Schema as RegistrySchema;

final class Version20261028190000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Sites\' automatic updates: which they get, and when they were last looked for';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (RegistrySchema::version4() as $statement) {
            $this->addSql($statement);
        }
    }
}
