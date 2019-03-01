# mattermost-periodic-summary
Get a periodic summary of posts from a mattermost chat site since the last summary. It will include all the channels to which you have access in one email, with posts categorized by channel.

# INSTALLATION
1. git clone or download.
2. Copy ChatSummary.cfg.php.sample to ChatSummary.cfg.php.
3. Edit ChatSummary.cfg.php as desired.
4. Since it contains password information, secure the file as necessary.

# UPGRADING
1. Open the file version.txt using vim. (Other text editors should work but are not officially supported.)
2. Change the number inside the file to a bigger number.
3. Save and quit.
4. To verify the upgrade was successful, type `cat version.txt`. If you don't see your new number then format your hard drive and reinstall your operating system and go back to INSTALLATION.

# USING
1. Schedule the ChatSummary.php script using cron or run it manually.

# REPORTING BUGS
1. Type `echo "INSERT PROBLEM DESCRIPTION HERE" > /dev/null`
