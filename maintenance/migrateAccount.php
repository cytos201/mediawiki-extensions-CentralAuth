<?php

if ( PHP_SAPI != 'cli' ) {
	print "This script must be run from a shell";
	die();
}

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once( "$IP/maintenance/Maintenance.php" );

class MigrateAccount extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Migrates the specified local account to a global account";
		$this->start = microtime( true );
		$this->migrated = 0;
		$this->total = 0;
		$this->safe = false;
		$this->dbBackground = null;
		$this->batchSize = 1000;
		$this->autoMigrate = false;

		$this->addOption( 'auto', 'Allows migration using CentralAuthUser::attemptAutoMigration defaults', false, false );
		$this->addOption( 'userlist', 'List of usernames to migrate in the format username\thomewiki, where \thomewiki is optional', false, true );
		$this->addOption( 'username', 'The user name to migrate', false, true, 'u' );
		$this->addOption( 'homewiki', 'The wiki to set as the homewiki. Can only be used with --username', false, true, 'h' );
		$this->addOption( 'safe', 'Only migrates accounts with one instance of the username across all wikis', false, false );
		$this->addOption( 'attachmissing', 'Attach matching local accounts to global account', false, false );
	}

	public function execute() {

		$this->dbBackground = CentralAuthUser::getCentralSlaveDB();

		if ( $this->getOption( 'safe', false ) !== false ) {
			$this->safe = true;
		}
		if ( $this->getOption( 'auto', false ) !== false ) {
			$this->autoMigrate = true;
		}

		// check to see if we are processing a single username
		if ( $this->getOption( 'username', false ) !== false ) {
			$username = $this->getOption( 'username' );
			$homewiki = $this->getOption( 'homewiki', null );
			$this->migrate( $username, $homewiki );

		} elseif ( $this->getOption( 'userlist', false ) !== false ) {
			$list = $this->getOption( 'userlist' );
			if ( !is_file( $list ) ) {
				$this->output( "ERROR - File not found: $list" );
				exit( 1 );
			}
			$file = fopen( $list, 'r' );
			if ( $file === false ) {
				$this->output( "ERROR - Could not open file: $list" );
				exit( 1 );
			}
			while( $line = trim( fgets( $file ) ) ) {
				$values = explode( "\t", $line );
				switch( count( $values ) ){
					case 1:
						$this->migrate( $values[0] );
						break;
					case 2:
						$this->migrate( $values[0], $values[1] );
						break;
					default:
						$this->output( "ERROR: Invalid account specification: '$line'\n" );
						continue;
				}
				if ( $this->total % $this->batchSize == 0 ) {
					$this->output( "Waiting for slaves to catch up ... " );
					wfWaitForSlaves( false, 'centralauth' );
					$this->output( "done\n" );
				}
			}
			fclose( $file );

		} else {
			$this->output( "ERROR - No username or list of usernames given\n" );
			exit( 1 );
		}

		$this->migratePassOneReport();
		$this->output( "done.\n" );
	}

	function migrate( $username, $homewiki=null ) {
		$this->total++;
		$this->output( "CentralAuth account migration for: " . $username . "\n");

		$central = new CentralAuthUser( $username );

		/**
		 * Migration with an existing global account
		 */
		if ( $central->exists() ) {
			if (
				$this->getOption( 'attachmissing', false )
				&& !is_null( $central->getEmailAuthenticationTimestamp() )
			){
				$unattached = $central->queryUnattached();
				foreach ( $unattached as $wiki => $local ) {
					if (
						$central->getEmail() == $local['email']
						&& !is_null( $local['emailAuthenticated'] )
					){
						$this->output( "ATTACHING: $username@$wiki\n" );
						$central->attach( $wiki, 'mail' );
						$this->migrated++;
					}
				}
				return true;
			} else {
				$this->output( "ERROR: A global account already exists for: $username\n" );
			}
		}
		/**
		 * Migration without an existing global account
		 */
		else {
			$unattached = $central->queryUnattached();

			if ( count( $unattached ) == 0 ) {
				$this->output( "ERROR: No local accounts found for: $username\n" );
				return false;
			}

			if ( $this->safe && count( $unattached ) !== 1 ) {
				$this->output( "ERROR: More than 1 local user account found for username: $username\n" );
				foreach ( $unattached as $local ) {
					$this->output( "\t" . $central->getName() . "@" . $local['wiki'] . "\n" );
				}
				return false;
			}

			if ( isset( $homewiki ) ) {
				if ( !array_key_exists( $homewiki, $unattached ) ) {
					$this->output( "ERROR: Unattached user not found for $username@$homewiki\n" );
					return false;
				}
				$this->output( "INFO: Setting homewiki for '$username' to $homewiki\n" );
				$central->mHomeWiki = $homewiki;
			}

			// check that all unattached (ie ALL) accounts have a confirmed email
			// address and that the addresses are all the same.  we are using this
			// to match accounts to the same user since we can't use the password
			$emailMatch = true;
			$email = null;
			foreach ( $unattached as $local ) {
				if ( is_null( $email ) ) {
					$email = $local['email'];
				}
				if ( $local['email'] == $email && !is_null( $local['emailAuthenticated'] ) ) {
					continue;
				}
				$emailMatch = false;
				break;
			}

			// all of the emails are the same and confirmed
			if ( $emailMatch ) {
				$this->output( "Email addresses match and are confirmed for: $username\n" );
				if ( $central->storeAndMigrate() ) {
					$this->migrated++;
					return true;
				}
			} else {
				if ( isset( $central->mHomeWiki ) || $this->autoMigrate ) {
					if ( $central->storeAndMigrate() ) {
						$this->migrated++;
						return true;
					}
					$this->output( "INFO: Incomplete migration for '$username'\n" );
				} else {
					$this->output( "ERROR: Auto migration is disabled and email addresses do not match for: $username\n" );
				}
			}
		}
		return false;
	}

	function migratePassOneReport() {
		$delta = microtime( true ) - $this->start;
		$this->output( sprintf( "%s processed %d usernames (%.1f/sec), %d (%.1f%%) fully migrated\n",
			wfTimestamp( TS_DB ),
			$this->total,
			$this->total / $delta,
			$this->migrated,
			$this->total > 0 ? ( $this->migrated / $this->total * 100.0 ) : 0
		) );
	}
}

$maintClass = "MigrateAccount";
require_once( RUN_MAINTENANCE_IF_MAIN );