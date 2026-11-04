<?php

declare(strict_types=1);

namespace Quire\Server\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Quire\Server\Registry\Schema as RegistrySchema;

final class Version20261104182000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'What the nightly checks of the backups found';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (RegistrySchema::version11() as $statement) {
            $this->addSql($statement);
        }
    }
}
