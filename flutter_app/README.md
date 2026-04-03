# NewsXpressLive — Flutter App

A cross-platform mobile app (Android & iOS) for the NewsXpressLive news platform.

## Tech Stack

| Layer        | Technology                           |
|--------------|--------------------------------------|
| UI           | Flutter 3.19+ / Material 3           |
| State        | Provider                             |
| Networking   | `http` package                       |
| Images       | `cached_network_image`               |
| Storage      | `shared_preferences`                 |
| HTML render  | `flutter_html`                       |
| Timestamps   | `timeago`                            |
| Share        | `share_plus`                         |
| Open links   | `url_launcher`                       |
| Fonts        | `google_fonts` (Noto Sans)           |

## Features (by Phase)

### ✅ Phase 1 — Core Architecture
- Project scaffold, folder structure
- Light/Dark theme (persisted)
- API service layer with error handling
- Data models: NewsArticle, Category, Comment
- Providers: NewsProvider, ThemeProvider, BookmarkProvider
- Bottom navigation (Home, Search, Bookmarks, Settings)

### 🔲 Phase 2 — Home Screen (next)
- Full news feed with 2-column grid
- Breaking news animated ticker
- Category filter chips
- Infinite scroll + pull-to-refresh

### 🔲 Phase 3 — Article Detail + Search
- Hero image + full HTML article body
- Comments (read + submit with moderation notice)
- Share + Bookmark actions
- Keyword search with debounce

### 🔲 Phase 4 — Polish + Offline
- Skeleton loading shimmer
- Offline cached reading
- Push notification support
- Animations & transitions

## Setup

1. **Install Flutter**: https://docs.flutter.dev/get-started/install

2. **Configure backend URL** — edit `lib/core/constants/api_endpoints.dart`:
   ```dart
   static const String baseUrl = 'https://your-domain.com/web';
   ```

3. **Install dependencies**:
   ```bash
   cd flutter_app
   flutter pub get
   ```

4. **Firebase Setup** (required for Auth, FCM, Analytics):

   ```bash
   # Step 1: FlutterFire CLI install karo
   dart pub global activate flutterfire_cli

   # Step 2: Configure (ye automatically 3 files banayega)
   flutterfire configure --project=your-firebase-project-id
   ```

   Ye 3 files generate hongi:
   - `lib/firebase_options.dart` ← auto-import in `main.dart`
   - `android/app/google-services.json`
   - `ios/Runner/GoogleService-Info.plist`

   > **Demo templates** already present hain — unhe reference ke liye dekho:
   > - `lib/firebase_options.demo.dart`
   > - `android/app/google-services.demo.json`
   > - `ios/Runner/GoogleService-Info.demo.plist`

5. **App Config** (AdMob IDs, bundle ID):
   ```bash
   cp lib/core/constants/app_config.demo.dart \
      lib/core/constants/app_config.dart
   # Phir YOUR_... values replace karo
   ```
   Template: `lib/core/constants/app_config.demo.dart`

6. **Run the app**:
   ```bash
   flutter run
   ```

## Backend API Endpoints

| Method | URL                           | Description             |
|--------|-------------------------------|-------------------------|
| GET    | `/api/more_news.php`          | Paginated news list     |
| GET    | `/api/more_news.php?category` | Category-filtered news  |
| GET    | `/news/detail_api.php?slug`   | Article detail          |
| GET    | `/api/latest_breaking.php`    | Latest breaking item    |
| GET    | `/api/search.php?q`           | Search articles         |
| GET    | `/api/categories.php`         | All categories          |
| GET    | `/api/comments.php?news_id`   | Comments for article    |
| POST   | `/api/comment_submit.php`     | Submit comment          |

## Project Structure

```
flutter_app/
├── lib/
│   ├── main.dart                     # Entry point
│   ├── app.dart                      # Root widget + providers
│   ├── main_navigation.dart          # BottomNavigationBar
│   ├── core/
│   │   ├── constants/                # Colors, strings, API URLs
│   │   ├── theme/                    # Light + dark theme
│   │   └── utils/                    # Date formatter, string utils
│   ├── data/
│   │   ├── models/                   # NewsArticle, Category, Comment
│   │   └── services/                 # ApiService, NewsService
│   ├── providers/                    # NewsProvider, ThemeProvider, BookmarkProvider
│   └── presentation/
│       ├── screens/                  # Home, Detail, Search, Bookmarks, Settings
│       └── widgets/                  # NewsCard, BreakingTicker, CategoryChips
└── pubspec.yaml
```
