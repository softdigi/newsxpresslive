/// Data models for the Agency Partner feature.

class AgencyProfile {
  final int id;
  final String name;
  final String email;
  final String apiKey;
  final String apiSecret;
  final String status;
  final double revenueSharePercent;
  final double walletBalance;
  final double totalEarned;
  final double thisMonthEarned;
  final int articlesTotal;
  final int articlesApproved;
  final int articlesPending;
  final int articlesRejected;

  const AgencyProfile({
    required this.id,
    required this.name,
    required this.email,
    required this.apiKey,
    required this.apiSecret,
    required this.status,
    required this.revenueSharePercent,
    required this.walletBalance,
    required this.totalEarned,
    required this.thisMonthEarned,
    required this.articlesTotal,
    required this.articlesApproved,
    required this.articlesPending,
    required this.articlesRejected,
  });

  factory AgencyProfile.fromJson(Map<String, dynamic> json) => AgencyProfile(
        id: _parseInt(json['id']),
        name: (json['name'] ?? '') as String,
        email: (json['email'] ?? '') as String,
        apiKey: (json['api_key'] ?? '') as String,
        apiSecret: (json['api_secret'] ?? '') as String,
        status: (json['status'] ?? 'active') as String,
        revenueSharePercent: _parseDouble(json['revenue_share_percent']),
        walletBalance: _parseDouble(json['wallet_balance']),
        totalEarned: _parseDouble(json['total_earned']),
        thisMonthEarned: _parseDouble(json['this_month_earned']),
        articlesTotal: _parseInt(json['articles_total']),
        articlesApproved: _parseInt(json['articles_approved']),
        articlesPending: _parseInt(json['articles_pending']),
        articlesRejected: _parseInt(json['articles_rejected']),
      );
}

class AgencyArticle {
  final int id;
  final String externalId;
  final String title;
  final String status;
  final DateTime submittedAt;
  final int impressions;
  final int clicks;
  final double revenueEarned;
  final String imageUrl;
  final String category;

  const AgencyArticle({
    required this.id,
    required this.externalId,
    required this.title,
    required this.status,
    required this.submittedAt,
    required this.impressions,
    required this.clicks,
    required this.revenueEarned,
    required this.imageUrl,
    required this.category,
  });

  factory AgencyArticle.fromJson(Map<String, dynamic> json) => AgencyArticle(
        id: _parseInt(json['id']),
        externalId: (json['external_id'] ?? '') as String,
        title: (json['title'] ?? '') as String,
        status: (json['status'] ?? 'pending') as String,
        submittedAt: _parseDate(json['submitted_at']),
        impressions: _parseInt(json['impressions']),
        clicks: _parseInt(json['clicks']),
        revenueEarned: _parseDouble(json['revenue_earned']),
        imageUrl: (json['image_url'] ?? '') as String,
        category: (json['category'] ?? '') as String,
      );
}

class AgencyTransaction {
  final int id;
  final String type; // credit / withdrawal / adjustment
  final double amount;
  final double balanceAfter;
  final String description;
  final DateTime createdAt;
  final String status;

  const AgencyTransaction({
    required this.id,
    required this.type,
    required this.amount,
    required this.balanceAfter,
    required this.description,
    required this.createdAt,
    required this.status,
  });

  factory AgencyTransaction.fromJson(Map<String, dynamic> json) =>
      AgencyTransaction(
        id: _parseInt(json['id']),
        type: (json['type'] ?? 'credit') as String,
        amount: _parseDouble(json['amount']),
        balanceAfter: _parseDouble(json['balance_after']),
        description: (json['description'] ?? '') as String,
        createdAt: _parseDate(json['created_at']),
        status: (json['status'] ?? 'completed') as String,
      );
}

class AgencyWithdrawal {
  final int id;
  final double amount;
  final String method;
  final String accountDetails;
  final String status;
  final DateTime requestedAt;
  final DateTime? completedAt;
  final String? transactionId;

  const AgencyWithdrawal({
    required this.id,
    required this.amount,
    required this.method,
    required this.accountDetails,
    required this.status,
    required this.requestedAt,
    this.completedAt,
    this.transactionId,
  });

  factory AgencyWithdrawal.fromJson(Map<String, dynamic> json) =>
      AgencyWithdrawal(
        id: _parseInt(json['id']),
        amount: _parseDouble(json['amount']),
        method: (json['method'] ?? '') as String,
        accountDetails: (json['account_details'] ?? '') as String,
        status: (json['status'] ?? 'requested') as String,
        requestedAt: _parseDate(json['requested_at']),
        completedAt: json['completed_at'] != null
            ? _parseDate(json['completed_at'])
            : null,
        transactionId: json['transaction_id'] as String?,
      );
}

class ArticleRevenue {
  final int articleId;
  final String title;
  final int impressions;
  final int clicks;
  final double revenue;
  final double agencyShare;

  const ArticleRevenue({
    required this.articleId,
    required this.title,
    required this.impressions,
    required this.clicks,
    required this.revenue,
    required this.agencyShare,
  });

  factory ArticleRevenue.fromJson(Map<String, dynamic> json) => ArticleRevenue(
        articleId: _parseInt(json['article_id']),
        title: (json['title'] ?? '') as String,
        impressions: _parseInt(json['impressions']),
        clicks: _parseInt(json['clicks']),
        revenue: _parseDouble(json['revenue']),
        agencyShare: _parseDouble(json['agency_share']),
      );
}

class AgencyRevenueSummary {
  final String date;
  final int impressions;
  final int clicks;
  final double grossRevenue;
  final double agencyShare;
  final double platformShare;
  final List<ArticleRevenue> articleBreakdown;

  const AgencyRevenueSummary({
    required this.date,
    required this.impressions,
    required this.clicks,
    required this.grossRevenue,
    required this.agencyShare,
    required this.platformShare,
    required this.articleBreakdown,
  });

  factory AgencyRevenueSummary.fromJson(Map<String, dynamic> json) =>
      AgencyRevenueSummary(
        date: (json['date'] ?? '') as String,
        impressions: _parseInt(json['impressions']),
        clicks: _parseInt(json['clicks']),
        grossRevenue: _parseDouble(json['gross_revenue']),
        agencyShare: _parseDouble(json['agency_share']),
        platformShare: _parseDouble(json['platform_share']),
        articleBreakdown: (json['article_breakdown'] as List<dynamic>? ?? [])
            .map((e) => ArticleRevenue.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}

// ── Helpers ──────────────────────────────────────────────────────────────

int _parseInt(dynamic v) {
  if (v == null) return 0;
  if (v is int) return v;
  return int.tryParse(v.toString()) ?? 0;
}

double _parseDouble(dynamic v) {
  if (v == null) return 0.0;
  if (v is double) return v;
  if (v is int) return v.toDouble();
  return double.tryParse(v.toString()) ?? 0.0;
}

DateTime _parseDate(dynamic v) {
  if (v == null) return DateTime.now();
  if (v is DateTime) return v;
  return DateTime.tryParse(v.toString()) ?? DateTime.now();
}
