<?php

declare(strict_types=1);

namespace Quire\Server\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Quire\Server\Registry\Schema as RegistrySchema;

final class Version20261104180000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Problems that need the owner, until they were told they\'re over';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (RegistrySchema::version9() as $statement) {
            $this->addSql($statement);
        }
    }
}
