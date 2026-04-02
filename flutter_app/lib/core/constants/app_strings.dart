/// String constants for the app.
class AppStrings {
  AppStrings._();

  static const String appName     = 'NewsXpressLive';
  static const String tagline     = 'Breaking News, Latest Updates';

  // ── Nav labels ────────────────────────────────────────────────────────
  static const String navHome      = 'Home';
  static const String navSearch    = 'Search';
  static const String navBookmarks = 'Bookmarks';
  static const String navSettings  = 'Settings';
  static const String navReporter  = 'Reporter';

  // ── Section titles ────────────────────────────────────────────────────
  static const String breaking     = 'BREAKING';
  static const String latestNews   = 'Latest News';
  static const String relatedNews  = 'Related News';
  static const String comments     = 'Comments';
  static const String leaveComment = 'Leave a Comment';

  // ── Buttons ───────────────────────────────────────────────────────────
  static const String readMore    = 'Read More';
  static const String share       = 'Share';
  static const String bookmark       = 'Bookmark';
  static const String bookmarkRemove = 'Remove bookmark';
  static const String postComment = 'Post Comment';
  static const String retry       = 'Retry';

  // ── Placeholders / errors ─────────────────────────────────────────────
  static const String searchHint          = 'Search news...';
  static const String noNews              = 'No news found.';
  static const String noBookmarks         = 'No bookmarks yet.\nBookmark an article to read it later.';
  static const String noComments          = 'No comments yet. Be the first!';
  static const String loadingFailed       = 'Failed to load. Please try again.';
  static const String commentSubmitted    = 'Comment submitted! It will appear after moderation.';
  static const String commentNameHint     = 'Your name';
  static const String commentContentHint  = 'Write your comment...';
  static const String commentEmailHint    = 'Email (optional)';

  // ── Settings labels ───────────────────────────────────────────────────
  static const String darkMode      = 'Dark Mode';
  static const String notifications = 'Notifications';
  static const String language      = 'Language';
  static const String about         = 'About';
  static const String privacyPolicy = 'Privacy Policy';
  static const String version       = 'Version';
  static const String appVersion    = '1.0.0';

  // ── Theme labels ──────────────────────────────────────────────────────
  static const String themeLight  = 'Light';
  static const String themeDark   = 'Dark';
  static const String themeSystem = 'Follow System';

  // ── Font size labels ──────────────────────────────────────────────────
  static const String fontSizeSmall  = 'Small';
  static const String fontSizeMedium = 'Medium';
  static const String fontSizeLarge  = 'Large';

  // ── Bookmarks ─────────────────────────────────────────────────────────
  static const String clearAllBookmarks = 'Clear All';
  static const String clearAllConfirm   = 'Remove all bookmarks?';
  static const String clearAllBody      = 'This will permanently delete all your saved articles.';
  static const String cancel            = 'Cancel';
  static const String clear             = 'Clear';

  // ── Search history ────────────────────────────────────────────────────
  static const String recentSearches = 'Recent Searches';
  static const String clearHistory   = 'Clear';

  // ── Auth ──────────────────────────────────────────────────────────────
  static const String signInWithGoogle  = 'Continue with Google';
  static const String continueAsGuest  = 'Continue as Guest';
  static const String signOut           = 'Sign Out';
  static const String signIn            = 'Sign In';
  static const String signedInAs        = 'Signed in as';
  static const String loginFailed       = 'Sign-in failed. Please try again.';
  static const String account           = 'Account';
  static const String guestUser         = 'Guest User';

  // ── Onboarding ────────────────────────────────────────────────────────
  static const String onboardingTitle         = 'Personalise Your Feed';
  static const String onboardingSubtitle      = 'Help us show you the most relevant news';
  static const String stepLocation            = 'Select Location';
  static const String stepLanguages           = 'Choose Languages';
  static const String stepInterests           = 'Pick Your Interests';
  static const String selectCountry           = 'Select Country';
  static const String selectState             = 'Select State';
  static const String selectDistrict          = 'Select District (Optional)';
  static const String primaryLanguage         = 'Primary Language';
  static const String secondaryLanguages      = 'Secondary Languages (Optional)';
  static const String pickCategories          = 'Pick at least one category';
  static const String next                    = 'Next';
  static const String skip                    = 'Skip';
  static const String done                    = 'Done';
  static const String saving                  = 'Saving…';

  // ── Reporter Mode ─────────────────────────────────────────────────────
  static const String submitNews        = 'Submit News';
  static const String newsTitle         = 'News Title';
  static const String newsDescription   = 'Description';
  static const String selectCategory    = 'Select Category';
  static const String selectLanguage    = 'Select Language';
  static const String addPhoto          = 'Add Photo';
  static const String changePhoto       = 'Change Photo';
  static const String submitForReview   = 'Submit for Review';
  static const String newsPending       = 'Your news has been submitted and is under review.';
  static const String mySubmissions     = 'My Submissions';
  static const String reportedBy        = 'Reported by';
  static const String agency            = 'Agency';
  static const String moreByReporter    = 'More by this Reporter';

  // ── Moderation ────────────────────────────────────────────────────────
  static const String moderationBlocked   = 'Content blocked';
  static const String moderationFlagged   = 'Content flagged for review';

  // ── Smart Notifications ───────────────────────────────────────────────
  static const String notifSectionTitle       = 'Notifications';
  static const String notifInterestFilter     = 'Interest-based filtering';
  static const String notifInterestFilterSub  = 'Only show notifications matching your categories';
  static const String notifBreakingAlerts     = 'Personalised breaking alerts';
  static const String notifBreakingAlertsSub  = 'Always show breaking news (bypasses category filter)';
  static const String notifQuietHours         = 'Quiet hours';
  static const String notifQuietHoursSub      = 'Suppress notifications during this window';
  static const String notifBestTime           = 'Your most-active hours';
  static const String notifBestTimeNone       = 'Open more articles to see your active hours';
  static const String notifLast24h            = 'Notifications shown (last 24 h)';
  static const String notifCategories         = 'Interest categories';
  static const String notifCategoriesNone     = 'Complete onboarding to set categories';

  // ── Offline / cache ───────────────────────────────────────────────────
  static const String offlineBanner        = 'You\'re offline — showing cached content';
  static const String navOffline           = 'Offline';
  static const String noOfflineArticles    = 'No articles saved for offline reading.';
  static const String offlineHint          = 'Open any article and tap the download icon to save it for offline reading.';
  static const String offlineSaved         = 'Offline';
  static const String offlineSync          = 'Sync now';
  static const String offlineSyncing       = 'Syncing…';
  static const String offlineLastSynced    = 'Last synced';
  static const String offlineClearTitle    = 'Remove all offline articles?';
  static const String offlineClearBody     = 'Downloaded articles will be deleted from this device.';
  static const String offlineDownload      = 'Save for offline';
  static const String offlineRemove        = 'Remove offline copy';
  static const String offlineSavedSnack    = 'Article saved for offline reading';
  static const String offlineRemovedSnack  = 'Offline copy removed';

  // ── Text-to-Speech ────────────────────────────────────────────────────
  static const String ttsListen    = 'Listen';
  static const String ttsListening = 'Listening…';
  static const String ttsLoading   = 'Starting…';
  static const String ttsPlaying   = 'Playing';
  static const String ttsPaused    = 'Paused';
  static const String ttsPause     = 'Pause';
  static const String ttsResume    = 'Resume';
  static const String ttsStop      = 'Stop';
  static const String ttsSpeed     = 'Playback speed';
}

