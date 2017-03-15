<?php

	function smol_media_path($service, $data_id, $remote_url, $append_file_ext=''){

		# Make sure we haven't already downloaded this
		$path = smol_media_get_cached($service, $data_id, $remote_url);
		if ($path) {
			return $path;
		}

		# https://foo.com => foo.com, http://foo.bar.net => foo.bar.net
		if (! preg_match('#//(.+)$#', $remote_url, $matches)){
			return null;
		}

		$path = 'media/' . $matches[1];

		# mlkshk does a thing where URLs don't include file extensions,
		# so we add our own.
		if ($append_file_ext){
			$path .= $append_file_ext;
		}

		# This is something that Twitter does for image URLs
		# If the path ends with '.jpg:large', drop the ':large' part
		if (substr($path, -6, 6) == ':large'){
			$path = substr($path, 0, -6);
		}

		$abs_path = $GLOBALS['cfg']['smol_data_dir'] . $path;

		if (file_exists($abs_path)){
			smol_media_set_cached($service, $data_id, $remote_url, $path);
			return $path;
		}

		$args = array();
		$more = array(
			'http_timeout' => 30,
			'follow_redirects' => 3
		);
		$rsp = http_get($remote_url, $args, $more);
		if (! $rsp['ok']){
			var_export($rsp);
			return null;
		}

		$dir = dirname($abs_path);
		if (! file_exists($dir)){
			mkdir($dir, 0755, true);
		}
		file_put_contents($abs_path, $rsp['body']);

		# Create a poster image for animated gifs. This assumes that
		# *all* gifs are animated, which ... I guessss is ok?
		# (20170315/dphiffer)
		if (preg_match('/^(.+)\.gif$/', $abs_path, $matches)){
			$poster_path = "{$matches[0]}.jpg";
			exec("/usr/bin/convert {$abs_path}[0] $poster_path");
		}

		smol_media_set_cached($service, $data_id, $remote_url, $path);

		return $path;
	}

	########################################################################

	function smol_media_get_cached($service, $data_id, $remote_url){

		$esc_service = addslashes($service);
		$esc_data_id = addslashes($data_id);
		$esc_remote_url = addslashes($remote_url);
		$rsp = db_fetch("
			SELECT *
			FROM smol_media
			WHERE href = '$esc_remote_url'
			  AND service = '$esc_service'
			  AND data_id = '$esc_data_id'
		");

		if ($rsp['rows']){

			$media = $rsp['rows'][0];

			if ($media['redirect'] &&
			    $media_redirect != $remote_url){
				return smol_media_path($service, $data_id, $media['redirect']);
			}

			if (file_exists($media['path'])) {
				return $media['path'];
			} else {
				# We have a db record of something that isn't there!
				$rsp = db_write("
					DELETE FROM smol_media
					WHERE service = '$esc_service'
					  AND data_id = '$esc_data_id'
					  AND href = '$esc_remote_url'
				");
			}
		}

		return null;
	}

	########################################################################

	function smol_media_set_cached($service, $data_id, $remote_url, $path) {
		$now = date('Y-m-d H:i:s');
		$rsp = db_insert('smol_media', array(
			'service' => addslashes($service),
			'data_id' => addslashes($data_id),
			'href' => addslashes($remote_url),
			'path' => addslashes($path),
			'saved_at' => $now
		));
		return $rsp;
	}

	# the end
