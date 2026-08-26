<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260826125619 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dojos (id BIGSERIAL NOT NULL, shortname VARCHAR(255) DEFAULT NULL, name VARCHAR(255) DEFAULT NULL, city VARCHAR(255) DEFAULT NULL, country_code VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX by_shortname ON dojos (shortname)');
        $this->addSql('COMMENT ON COLUMN dojos.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN dojos.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE kyudojins (id BIGSERIAL NOT NULL, license_id VARCHAR(255) DEFAULT NULL, firstname VARCHAR(255) DEFAULT NULL, lastname VARCHAR(255) DEFAULT NULL, federation_club VARCHAR(255) DEFAULT NULL, federation_country_code VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX by_firstname_lastname ON kyudojins (firstname, lastname)');
        $this->addSql('CREATE INDEX by_lastname_firstname ON kyudojins (lastname, firstname)');
        $this->addSql('CREATE UNIQUE INDEX by_license_id ON kyudojins (license_id)');
        $this->addSql('COMMENT ON COLUMN kyudojins.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN kyudojins.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE matches (id BIGSERIAL NOT NULL, taikai_id BIGINT NOT NULL, team1_id BIGINT DEFAULT NULL, team2_id BIGINT DEFAULT NULL, "index" SMALLINT NOT NULL, level SMALLINT NOT NULL, winner SMALLINT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_62615BA50662AAB ON matches (taikai_id)');
        $this->addSql('CREATE INDEX IDX_62615BAE72BCFA4 ON matches (team1_id)');
        $this->addSql('CREATE INDEX IDX_62615BAF59E604A ON matches (team2_id)');
        $this->addSql('COMMENT ON COLUMN matches.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN matches.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE participants (id BIGSERIAL NOT NULL, participating_dojo_id BIGINT NOT NULL, team_id BIGINT DEFAULT NULL, kyudojin_id BIGINT DEFAULT NULL, firstname VARCHAR(255) DEFAULT NULL, lastname VARCHAR(255) DEFAULT NULL, club VARCHAR(255) DEFAULT \'\' NOT NULL, "index" INT DEFAULT NULL, index_in_team INT DEFAULT NULL, intermediate_rank INT DEFAULT NULL, rank INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_716970923AEFF238 ON participants (participating_dojo_id)');
        $this->addSql('CREATE INDEX IDX_71697092296CD8AE ON participants (team_id)');
        $this->addSql('CREATE INDEX IDX_71697092FEF1BF07 ON participants (kyudojin_id)');
        $this->addSql('CREATE UNIQUE INDEX participants_by_participating_dojo_index ON participants (participating_dojo_id, index)');
        $this->addSql('CREATE UNIQUE INDEX by_participants_participating_dojo_kyudojin ON participants (participating_dojo_id, kyudojin_id)');
        $this->addSql('CREATE UNIQUE INDEX teams_by_team_index_in_team ON participants (team_id, index_in_team)');
        $this->addSql('COMMENT ON COLUMN participants.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN participants.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE participating_dojos (id BIGSERIAL NOT NULL, taikai_id BIGINT NOT NULL, dojo_id BIGINT NOT NULL, display_name VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_F112101B50662AAB ON participating_dojos (taikai_id)');
        $this->addSql('CREATE INDEX IDX_F112101B32F09E9C ON participating_dojos (dojo_id)');
        $this->addSql('CREATE UNIQUE INDEX by_taikai_dojo ON participating_dojos (taikai_id, dojo_id)');
        $this->addSql('COMMENT ON COLUMN participating_dojos.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN participating_dojos.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE results (id BIGSERIAL NOT NULL, score_id BIGINT NOT NULL, match_id BIGINT DEFAULT NULL, round INT DEFAULT NULL, "index" INT DEFAULT NULL, status VARCHAR(255) DEFAULT NULL, value INT DEFAULT NULL, final BOOLEAN DEFAULT false NOT NULL, overriden BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9FA3E41412EB0A51 ON results (score_id)');
        $this->addSql('CREATE INDEX IDX_9FA3E4142ABEACD6 ON results (match_id)');
        $this->addSql('COMMENT ON COLUMN results.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN results.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE scoreboards (id BIGSERIAL NOT NULL, participating_dojo_id BIGINT DEFAULT NULL, api_key VARCHAR(255) DEFAULT NULL, delay INT DEFAULT 15 NOT NULL, nb_participants INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C09A6D073AEFF238 ON scoreboards (participating_dojo_id)');
        $this->addSql('CREATE UNIQUE INDEX index_scoreboards_on_api_key ON scoreboards (api_key)');
        $this->addSql('COMMENT ON COLUMN scoreboards.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN scoreboards.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE scores (id BIGSERIAL NOT NULL, participant_id BIGINT DEFAULT NULL, team_id BIGINT DEFAULT NULL, match_id BIGINT DEFAULT NULL, hits INT DEFAULT 0 NOT NULL, value INT DEFAULT 0 NOT NULL, intermediate_hits INT DEFAULT 0 NOT NULL, intermediate_value INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_750375E9D1C3019 ON scores (participant_id)');
        $this->addSql('CREATE INDEX IDX_750375E296CD8AE ON scores (team_id)');
        $this->addSql('CREATE INDEX IDX_750375E2ABEACD6 ON scores (match_id)');
        $this->addSql('CREATE UNIQUE INDEX by_participant_id ON scores (participant_id, match_id)');
        $this->addSql('CREATE UNIQUE INDEX by_team_id_match_id ON scores (team_id, match_id)');
        $this->addSql('COMMENT ON COLUMN scores.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN scores.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE sessions (id BIGSERIAL NOT NULL, user_id BIGINT NOT NULL, ip_address VARCHAR(255) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9A609D13A76ED395 ON sessions (user_id)');
        $this->addSql('COMMENT ON COLUMN sessions.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN sessions.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE staff_roles (id BIGSERIAL NOT NULL, code VARCHAR(255) DEFAULT NULL, label JSON DEFAULT \'{}\' NOT NULL, description JSON DEFAULT \'{}\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX by_staff_roles_code ON staff_roles (code)');
        $this->addSql('COMMENT ON COLUMN staff_roles.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN staff_roles.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE staffs (id BIGSERIAL NOT NULL, taikai_id BIGINT NOT NULL, role_id BIGINT NOT NULL, participating_dojo_id BIGINT DEFAULT NULL, user_id BIGINT DEFAULT NULL, firstname VARCHAR(255) DEFAULT NULL, lastname VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_54D539050662AAB ON staffs (taikai_id)');
        $this->addSql('CREATE INDEX IDX_54D5390D60322AC ON staffs (role_id)');
        $this->addSql('CREATE INDEX IDX_54D53903AEFF238 ON staffs (participating_dojo_id)');
        $this->addSql('CREATE INDEX IDX_54D5390A76ED395 ON staffs (user_id)');
        $this->addSql('COMMENT ON COLUMN staffs.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN staffs.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE tachis (id BIGSERIAL NOT NULL, participating_dojo_id BIGINT NOT NULL, match_id BIGINT DEFAULT NULL, round INT NOT NULL, "index" INT NOT NULL, finished BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_974782943AEFF238 ON tachis (participating_dojo_id)');
        $this->addSql('CREATE INDEX IDX_974782942ABEACD6 ON tachis (match_id)');
        $this->addSql('CREATE UNIQUE INDEX index_tachis_on_participating_dojo_id_and_index_and_round ON tachis (participating_dojo_id, index, round)');
        $this->addSql('COMMENT ON COLUMN tachis.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN tachis.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE taikai_events (id BIGSERIAL NOT NULL, taikai_id BIGINT NOT NULL, user_id BIGINT NOT NULL, category VARCHAR(255) DEFAULT NULL, message TEXT DEFAULT NULL, data JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_7734660D50662AAB ON taikai_events (taikai_id)');
        $this->addSql('CREATE INDEX IDX_7734660DA76ED395 ON taikai_events (user_id)');
        $this->addSql('COMMENT ON COLUMN taikai_events.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE taikai_transitions (id BIGSERIAL NOT NULL, taikai_id BIGINT NOT NULL, to_state VARCHAR(255) NOT NULL, sort_key INT NOT NULL, most_recent BOOLEAN NOT NULL, metadata JSON DEFAULT \'{}\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_4F3D83450662AAB ON taikai_transitions (taikai_id)');
        $this->addSql('CREATE UNIQUE INDEX index_taikai_transitions_parent_sort ON taikai_transitions (taikai_id, sort_key)');
        $this->addSql('COMMENT ON COLUMN taikai_transitions.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN taikai_transitions.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE taikais (id BIGSERIAL NOT NULL, shortname VARCHAR(255) DEFAULT NULL, name VARCHAR(255) DEFAULT NULL, description TEXT DEFAULT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, form VARCHAR(255) DEFAULT NULL, scoring VARCHAR(255) DEFAULT \'kinteki\' NOT NULL, total_num_arrows SMALLINT DEFAULT 12 NOT NULL, num_targets SMALLINT DEFAULT 6 NOT NULL, tachi_size SMALLINT DEFAULT 3 NOT NULL, distributed BOOLEAN DEFAULT true NOT NULL, category VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX taikais_by_form ON taikais (form)');
        $this->addSql('CREATE INDEX taikais_by_scoring ON taikais (scoring)');
        $this->addSql('CREATE UNIQUE INDEX by_taikais_shortname ON taikais (shortname)');
        $this->addSql('COMMENT ON COLUMN taikais.start_date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN taikais.end_date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN taikais.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN taikais.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE teams (id BIGSERIAL NOT NULL, participating_dojo_id BIGINT NOT NULL, shortname VARCHAR(255) NOT NULL, "index" INT DEFAULT NULL, mixed BOOLEAN DEFAULT false NOT NULL, intermediate_rank INT DEFAULT NULL, rank INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_96C222583AEFF238 ON teams (participating_dojo_id)');
        $this->addSql('CREATE UNIQUE INDEX teams_by_participating_dojo_index ON teams (participating_dojo_id, index)');
        $this->addSql('CREATE UNIQUE INDEX by_teams_shortname ON teams (participating_dojo_id, shortname)');
        $this->addSql('COMMENT ON COLUMN teams.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN teams.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE users (id BIGSERIAL NOT NULL, email_address VARCHAR(255) NOT NULL, password_digest VARCHAR(255) DEFAULT NULL, firstname VARCHAR(255) DEFAULT NULL, lastname VARCHAR(255) DEFAULT NULL, locale VARCHAR(255) DEFAULT \'fr\' NOT NULL, admin BOOLEAN DEFAULT false NOT NULL, confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, confirmation_token VARCHAR(255) DEFAULT NULL, confirmation_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, unconfirmed_email VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9C05FB297 ON users (confirmation_token)');
        $this->addSql('CREATE UNIQUE INDEX index_users_on_email_address ON users (email_address)');
        $this->addSql('COMMENT ON COLUMN users.confirmed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.confirmation_sent_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN users.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE matches ADD CONSTRAINT FK_62615BA50662AAB FOREIGN KEY (taikai_id) REFERENCES taikais (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE matches ADD CONSTRAINT FK_62615BAE72BCFA4 FOREIGN KEY (team1_id) REFERENCES teams (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE matches ADD CONSTRAINT FK_62615BAF59E604A FOREIGN KEY (team2_id) REFERENCES teams (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE participants ADD CONSTRAINT FK_716970923AEFF238 FOREIGN KEY (participating_dojo_id) REFERENCES participating_dojos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE participants ADD CONSTRAINT FK_71697092296CD8AE FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE participants ADD CONSTRAINT FK_71697092FEF1BF07 FOREIGN KEY (kyudojin_id) REFERENCES kyudojins (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE participating_dojos ADD CONSTRAINT FK_F112101B50662AAB FOREIGN KEY (taikai_id) REFERENCES taikais (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE participating_dojos ADD CONSTRAINT FK_F112101B32F09E9C FOREIGN KEY (dojo_id) REFERENCES dojos (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE results ADD CONSTRAINT FK_9FA3E41412EB0A51 FOREIGN KEY (score_id) REFERENCES scores (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE results ADD CONSTRAINT FK_9FA3E4142ABEACD6 FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE scoreboards ADD CONSTRAINT FK_C09A6D073AEFF238 FOREIGN KEY (participating_dojo_id) REFERENCES participating_dojos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE scores ADD CONSTRAINT FK_750375E9D1C3019 FOREIGN KEY (participant_id) REFERENCES participants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE scores ADD CONSTRAINT FK_750375E296CD8AE FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE scores ADD CONSTRAINT FK_750375E2ABEACD6 FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE sessions ADD CONSTRAINT FK_9A609D13A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE staffs ADD CONSTRAINT FK_54D539050662AAB FOREIGN KEY (taikai_id) REFERENCES taikais (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE staffs ADD CONSTRAINT FK_54D5390D60322AC FOREIGN KEY (role_id) REFERENCES staff_roles (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE staffs ADD CONSTRAINT FK_54D53903AEFF238 FOREIGN KEY (participating_dojo_id) REFERENCES participating_dojos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE staffs ADD CONSTRAINT FK_54D5390A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE tachis ADD CONSTRAINT FK_974782943AEFF238 FOREIGN KEY (participating_dojo_id) REFERENCES participating_dojos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE tachis ADD CONSTRAINT FK_974782942ABEACD6 FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE taikai_events ADD CONSTRAINT FK_7734660D50662AAB FOREIGN KEY (taikai_id) REFERENCES taikais (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE taikai_events ADD CONSTRAINT FK_7734660DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE taikai_transitions ADD CONSTRAINT FK_4F3D83450662AAB FOREIGN KEY (taikai_id) REFERENCES taikais (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE teams ADD CONSTRAINT FK_96C222583AEFF238 FOREIGN KEY (participating_dojo_id) REFERENCES participating_dojos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Index unique partiel : garantit qu'un taikai n'a qu'une seule transition
        // courante. Doctrine ne sait pas exprimer la clause WHERE via les attributs,
        // il est donc ajouté à la main (identique à l'index Rails du même nom).
        $this->addSql('CREATE UNIQUE INDEX index_taikai_transitions_parent_most_recent ON taikai_transitions (taikai_id, most_recent) WHERE most_recent');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE matches DROP CONSTRAINT FK_62615BA50662AAB');
        $this->addSql('ALTER TABLE matches DROP CONSTRAINT FK_62615BAE72BCFA4');
        $this->addSql('ALTER TABLE matches DROP CONSTRAINT FK_62615BAF59E604A');
        $this->addSql('ALTER TABLE participants DROP CONSTRAINT FK_716970923AEFF238');
        $this->addSql('ALTER TABLE participants DROP CONSTRAINT FK_71697092296CD8AE');
        $this->addSql('ALTER TABLE participants DROP CONSTRAINT FK_71697092FEF1BF07');
        $this->addSql('ALTER TABLE participating_dojos DROP CONSTRAINT FK_F112101B50662AAB');
        $this->addSql('ALTER TABLE participating_dojos DROP CONSTRAINT FK_F112101B32F09E9C');
        $this->addSql('ALTER TABLE results DROP CONSTRAINT FK_9FA3E41412EB0A51');
        $this->addSql('ALTER TABLE results DROP CONSTRAINT FK_9FA3E4142ABEACD6');
        $this->addSql('ALTER TABLE scoreboards DROP CONSTRAINT FK_C09A6D073AEFF238');
        $this->addSql('ALTER TABLE scores DROP CONSTRAINT FK_750375E9D1C3019');
        $this->addSql('ALTER TABLE scores DROP CONSTRAINT FK_750375E296CD8AE');
        $this->addSql('ALTER TABLE scores DROP CONSTRAINT FK_750375E2ABEACD6');
        $this->addSql('ALTER TABLE sessions DROP CONSTRAINT FK_9A609D13A76ED395');
        $this->addSql('ALTER TABLE staffs DROP CONSTRAINT FK_54D539050662AAB');
        $this->addSql('ALTER TABLE staffs DROP CONSTRAINT FK_54D5390D60322AC');
        $this->addSql('ALTER TABLE staffs DROP CONSTRAINT FK_54D53903AEFF238');
        $this->addSql('ALTER TABLE staffs DROP CONSTRAINT FK_54D5390A76ED395');
        $this->addSql('ALTER TABLE tachis DROP CONSTRAINT FK_974782943AEFF238');
        $this->addSql('ALTER TABLE tachis DROP CONSTRAINT FK_974782942ABEACD6');
        $this->addSql('ALTER TABLE taikai_events DROP CONSTRAINT FK_7734660D50662AAB');
        $this->addSql('ALTER TABLE taikai_events DROP CONSTRAINT FK_7734660DA76ED395');
        $this->addSql('ALTER TABLE taikai_transitions DROP CONSTRAINT FK_4F3D83450662AAB');
        $this->addSql('ALTER TABLE teams DROP CONSTRAINT FK_96C222583AEFF238');
        $this->addSql('DROP TABLE dojos');
        $this->addSql('DROP TABLE kyudojins');
        $this->addSql('DROP TABLE matches');
        $this->addSql('DROP TABLE participants');
        $this->addSql('DROP TABLE participating_dojos');
        $this->addSql('DROP TABLE results');
        $this->addSql('DROP TABLE scoreboards');
        $this->addSql('DROP TABLE scores');
        $this->addSql('DROP TABLE sessions');
        $this->addSql('DROP TABLE staff_roles');
        $this->addSql('DROP TABLE staffs');
        $this->addSql('DROP TABLE tachis');
        $this->addSql('DROP TABLE taikai_events');
        $this->addSql('DROP TABLE taikai_transitions');
        $this->addSql('DROP TABLE taikais');
        $this->addSql('DROP TABLE teams');
        $this->addSql('DROP TABLE users');
    }
}
