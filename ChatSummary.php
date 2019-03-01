<?php

// TODO:
// Error handling is not best practice.

require_once 'ChatSummary.cfg.php';

function getPosts() {
    global $CHAT_SUMMARY_URL, $CHAT_SUMMARY_TEAM_NAME, $CHAT_SUMMARY_LOGIN_STRING, $CHAT_SUMMARY_CUTOFF, $CHAT_SUMMARY_MAIL_FROM, $CHAT_SUMMARY_MAIL_RECIPIENT;

    $curl = curl_init();
    $cookie_file_path = tempnam(sys_get_temp_dir(), 'coo');
    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_HEADER => true,
        CURLOPT_URL => "{$CHAT_SUMMARY_URL}/api/v4/users/login",
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $CHAT_SUMMARY_LOGIN_STRING,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_USERAGENT => "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)",
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_COOKIEFILE => $cookie_file_path,
        CURLOPT_COOKIEJAR => $cookie_file_path,
    ));
    $response_str = curl_exec($curl);


    // Parse out token line
    $matches = array();
    preg_match('/^Token: (.+)$/m', $response_str, $matches);
    if (empty($matches[1])) {
        curl_close($curl);
        return "Error while logging in.";
    }

    // Because we included the header in the output, need to locate the actual json string so we can get the user id, which is not the same as the username.
    $user_id = '';
    $pos = strpos($response_str, '{"id":');
    if ($pos !== FALSE) {
        $user_arr = json_decode(substr($response_str, $pos), true);
        if (!empty($user_arr['id'])) {
            $user_id = $user_arr['id'];
        }
    }
    if (empty($user_id)) {
        curl_close($curl);
        return "Can't locate user id.";
    }

    // Get list of teams and find the id for the team.
    curl_setopt_array($curl, array(
        CURLOPT_HEADER => false,
        CURLOPT_URL => "{$CHAT_SUMMARY_URL}/api/v4/teams",
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            "Authorization: Bearer {$matches[1]}",
        ),
        CURLOPT_POST => false,
    ));
    $response_str = curl_exec($curl);
    $response_arr = json_decode($response_str, true);
    if (empty($response_arr)) {
        curl_close($curl);
        return "Can't get list of teams.";
    }

    $team_id = '';
    foreach ($response_arr as $team) {
        if ($team['name'] == $CHAT_SUMMARY_TEAM_NAME) {
            $team_id = $team['id'];
            break;
        }
    }
    if (empty($team_id)) {
        curl_close($curl);
        return "Can't locate civicrm team id.";
    }

    // Get list of channels
    curl_setopt_array($curl, array(
        CURLOPT_URL => "{$CHAT_SUMMARY_URL}/api/v4/users/{$user_id}/teams/{$team_id}/channels",
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            "Authorization: Bearer {$matches[1]}",
        ),
        CURLOPT_POST => false,
    ));
    $response_str = curl_exec($curl);
    $response_arr = json_decode($response_str, true);
    if (empty($response_arr)) {
        curl_close($curl);
        return "Can't get list of channels.";
    }

    // Set defaults
    $cutoff = time() - $CHAT_SUMMARY_CUTOFF;
    $user_id_cache = array();
    $mail_body = array();

    // If present, load from cache instead.
    readCache($cutoff, $user_id_cache);

    // They want microseconds included.
    $cutoff *= 1000;

    // Loop thru channels
    foreach ($response_arr as $channel) {
        curl_setopt_array($curl, array(
            CURLOPT_URL => "{$CHAT_SUMMARY_URL}/api/v4/channels/{$channel['id']}/posts?since={$cutoff}",
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                "Authorization: Bearer {$matches[1]}",
            ),
            CURLOPT_POST => false,
        ));
        $response_str = curl_exec($curl);
        $posts_arr = json_decode($response_str, true);
        if (!empty($posts_arr['posts'])) {
            $mail_body[] = "\n\n******************\n{$channel['name']}\n******************\n\n";

            // They're in random order. There's an order element, but it seems hard to use and this is just as good unless there's a ton of posts.
            uasort(
                $posts_arr['posts'],
                function($a, $b) {
                    if ($a['create_at'] < $b['create_at']) {
                        return -1;
                    } elseif ($a['create_at'] > $b['create_at']) {
                        return 1;
                    }
                    return 0;
                }
            );

            // Loop through posts
            foreach ($posts_arr['posts'] as $post) {
                if (!isset($user_id_cache[$post['user_id']])) {
                    $username = getUsername($curl, $matches[1], $post['user_id']);
                    if (empty($username)) {
                        $user_id_cache[$post['user_id']] = 'Anonymous';
                    } else {
                        $user_id_cache[$post['user_id']] = $username;
                    }
                }
                $post_date = date('Y-m-d H:i', floor($post['create_at'] / 1000));
                $mail_body[] = "{$post_date}: {$user_id_cache[$post['user_id']]}: {$post['message']}\n";
            }
        }
    }

    curl_close($curl);

    if (mail(
        $CHAT_SUMMARY_MAIL_RECIPIENT,
        "Chat Summary",
        implode("\r\n", $mail_body),
        "From: {$CHAT_SUMMARY_MAIL_FROM}\r\nContent-Type: text/plain",
        "-f{$CHAT_SUMMARY_MAIL_FROM}"
        ) === FALSE) {
            return "Error sending mail.";
    }

    return '';
}

// Map a chat user id to name
function getUsername($curl, $auth_token, $id) {
    global $CHAT_SUMMARY_URL;
    curl_setopt_array($curl, array(
        CURLOPT_URL => "{$CHAT_SUMMARY_URL}/api/v4/users/{$id}",
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            "Authorization: Bearer {$auth_token}",
        ),
        CURLOPT_POST => false,
    ));
    $response_str = curl_exec($curl);
    $response_arr = json_decode($response_str, true);
    if (!empty($response_arr['username'])) {
        return $response_arr['username'];
    }
    return null;
}

// If present, load from cache from last run.
function readCache(&$cutoff, &$user_id_cache) {
    global $CHAT_SUMMARY_CACHE_DIR;
    $filename = rtrim($CHAT_SUMMARY_CACHE_DIR, "\\/") . '/chat_summary_cache';
    if (file_exists($filename)) {
        $fp = fopen($filename, 'r');
        if ($fp) {
            // first row is the last run timestamp
            $row = fgetcsv($fp);
            $cutoff = $row[0];

            // read in id=>name mappings
            while (($row = fgetcsv($fp)) !== FALSE) {
                $user_id_cache[$row[0]] = $row[1];
            }
            fclose($fp);
        }
    }
}

// Overwrite the cache with updated values
function setCache($user_id_cache) {
    global $CHAT_SUMMARY_CACHE_DIR;
    $filename = rtrim($CHAT_SUMMARY_CACHE_DIR, "\\/") . '/chat_summary_cache';
    $fp = fopen($filename, 'w');
    if ($fp) {
        fputcsv($fp, array(time()));

        foreach ($user_id_cache as $id => $name) {
            fputcsv($fp, array($id, $name));
        }
        fclose($fp);
    }
}

$err = getPosts();
if (!empty($err)) {
    echo "\n$err\n";
}
