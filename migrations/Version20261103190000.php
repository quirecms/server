<?php

declare(strict_types=1);

namespace Quire\Server\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Quire\Server\Registry\Schema as RegistrySchema;

final class Version20261103190000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Each site\'s addresses, which never overlap another\'s';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (RegistrySchema::version8() as $statement) {
            $this->addSql($statement);
        }
    }
}
