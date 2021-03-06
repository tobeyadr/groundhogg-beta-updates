<?php

namespace GroundhoggBetaUpdates;

use stdClass;

class Update_Groundhogg {

	private $slug; // plugin slug
	private $pluginData; // plugin data
	private $username; // GitHub username
	private $repo; // GitHub repo name
	private $pluginFile; // __FILE__ of our plugin
	private $githubAPIResult; // holds data from GitHub
	private $accessToken; // GitHub private repo token

	function __construct() {
		add_filter( "pre_set_site_transient_update_plugins", array( $this, "setTransient" ) );
		add_filter( "plugins_api", array( $this, "setPluginInfo" ), 10, 3 );
		add_filter( "upgrader_post_install", array( $this, "postInstall" ), 10, 3 );

		$this->pluginFile  = GROUNDHOGG__FILE__;
		$this->username    = 'tobeyadr';
		$this->repo        = 'groundhogg';
		$this->accessToken = '';
	}

	// Get information regarding our plugin from WordPress
	private function initPluginData() {

		$this->slug = plugin_basename( $this->pluginFile );

		if ( ! file_exists( $this->pluginFile ) ) {
			return;
		}

		$this->pluginData = get_plugin_data( $this->pluginFile );
	}

	// Get information regarding our plugin from GitHub
	private function getRepoReleaseInfo() {
		// Only do this once
		if ( ! empty( $this->githubAPIResult ) ) {
			return;
		}

		// Query the GitHub API
		$url = "https://api.github.com/repos/{$this->username}/{$this->repo}/tags";

		// We need the access token for private repos
		if ( ! empty( $this->accessToken ) ) {
			$url = add_query_arg( array( "access_token" => $this->accessToken ), $url );
		}

		// Get the results
		$this->githubAPIResult = wp_remote_retrieve_body( wp_remote_get( $url ) );
		if ( ! empty( $this->githubAPIResult ) ) {
			$this->githubAPIResult = @json_decode( $this->githubAPIResult );
		}


		// Use only the latest release
		if ( is_array( $this->githubAPIResult ) ) {

			$data = [];
			$i    = 0;

			while ( empty( $data ) ) {
				if ( strpos( $this->githubAPIResult[ $i ]->name, 'alpha' ) == true || strpos( $this->githubAPIResult[ $i ]->name, 'beta' ) == true || strpos( $this->githubAPIResult[ $i ]->name, 'dev' ) == true ) {
					$data = $this->githubAPIResult[ $i ];
				}
				$i ++;
			}

			$this->githubAPIResult = $data;
		}
	}

	// Push in plugin version information to get the update notification
	public function setTransient( $transient ) {
		// If we have checked the plugin data before, don't re-check
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Get plugin & GitHub release information
		$this->initPluginData();
		$this->getRepoReleaseInfo();

		$doUpdate = 0;
		if ( array_key_exists( 'checked', $transient ) ) {

			// Check the versions if we need to do an update
			if ( array_key_exists( $this->slug, $transient->checked ) ) {

				$doUpdate = version_compare( $this->githubAPIResult->name, $transient->checked[ $this->slug ] );
			}

		}

		// Update the transient to include our updated plugin data
		if ( $doUpdate == 1 ) {
			$package = $this->githubAPIResult->zipball_url;

			// Include the access token for private GitHub repos
			if ( ! empty( $this->accessToken ) ) {
				$package = add_query_arg( array( "access_token" => $this->accessToken ), $package );
			}

			$obj                                = new stdClass();
			$obj->slug                          = $this->slug;
			$obj->new_version                   = $this->githubAPIResult->name;
			$obj->url                           = $this->pluginData["PluginURI"];
			$obj->package                       = $package;
			$transient->response[ $this->slug ] = $obj;
		}

		return $transient;
	}

	// Push in plugin version information to display in the details lightbox
	public function setPluginInfo( $false, $action, $response ) {
		// Get plugin & GitHub release information
		$this->initPluginData();
		$this->getRepoReleaseInfo();

		// If nothing is found, do nothing
		if ( empty( $response->slug ) || $response->slug != $this->slug ) {
			return false;
		}

		// Add our plugin information
//		$response->last_updated = $this->githubAPIResult->published_at;

		$response->name        = $this->pluginData["Name"];
		$response->slug        = $this->slug;
		$response->plugin_name = $this->pluginData["Name"];
		$response->version     = $this->githubAPIResult->name;
		$response->author      = $this->pluginData["AuthorName"];
		$response->homepage    = $this->pluginData["PluginURI"];

		// This is our release download zip file
		$downloadLink = $this->githubAPIResult->zipball_url;

		// Include the access token for private GitHub repos
		if ( ! empty( $this->accessToken ) ) {
			$downloadLink = add_query_arg(
				array( "access_token" => $this->accessToken ),
				$downloadLink
			);
		}
		$response->download_link = $downloadLink;

		// Create tabs in the lightbox
		$response->sections = array(
			'description' => $this->pluginData["Description"],
			'changelog'   => 'For the most recent updates please see our <a href="https://github.com/tobeyadr/Groundhogg">GitHub repo listing</a>.',
		);

		return $response;
	}

	// Perform additional actions to successfully install our plugin
	public function postInstall( $true, $hook_extra, $result ) {

		// Check if updating Groundhogg, otherwise exit out...
		$this->initPluginData();

		$plugin = $hook_extra[ 'plugin' ];
		$slug   = plugin_basename( $plugin );

		// Not core Groundhogg...
		if ( $slug != $this->slug ){
			return $true;
		}

		// Remember if our plugin was previously activated
		$wasActivated = is_plugin_active( $this->slug );

		// Since we are hosted in GitHub, our plugin folder would have a dirname of
		// reponame-tagname change it to our original one:
		global $wp_filesystem;
		$pluginFolder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->slug );
		$wp_filesystem->move( $result['destination'], $pluginFolder );
		$result['destination'] = $pluginFolder;

		// Re-activate plugin if needed
		if ( ! $wasActivated ) {
			$activate = activate_plugin( $this->slug );
		}

		return $true;
	}
}