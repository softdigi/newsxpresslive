/// Data models for Mandi Bhav (Agricultural Market Rates) feature.

class MandiInfo {
  final int id;
  final String name;
  final String nameHi;
  final String? city;
  final double? distanceKm;

  const MandiInfo({
    required this.id,
    required this.name,
    required this.nameHi,
    this.city,
    this.distanceKm,
  });

  factory MandiInfo.fromJson(Map<String, dynamic> j) => MandiInfo(
        id: j['id'] as int,
        name: j['name'] as String,
        nameHi: (j['name_hi'] as String?) ?? j['name'] as String,
        city: j['city'] as String?,
        distanceKm: (j['distance_km'] as num?)?.toDouble(),
      );
}

class MandiRate {
  final int commodityId;
  final String commodity;
  final String commodityEn;
  final String category;
  final String unit;
  final double minPrice;
  final double maxPrice;
  final double modalPrice;
  final double? msp;
  final double? change;
  final double? changePercent;
  final String trend; // 'up' | 'down' | 'stable'
  final String? arrivals;

  const MandiRate({
    required this.commodityId,
    required this.commodity,
    required this.commodityEn,
    required this.category,
    required this.unit,
    required this.minPrice,
    required this.maxPrice,
    required this.modalPrice,
    this.msp,
    this.change,
    this.changePercent,
    required this.trend,
    this.arrivals,
  });

  factory MandiRate.fromJson(Map<String, dynamic> j) => MandiRate(
        commodityId: j['commodity_id'] as int,
        commodity: j['commodity'] as String,
        commodityEn: j['commodity_en'] as String,
        category: j['category'] as String,
        unit: j['unit'] as String,
        minPrice: (j['min_price'] as num).toDouble(),
        maxPrice: (j['max_price'] as num).toDouble(),
        modalPrice: (j['modal_price'] as num).toDouble(),
        msp: (j['msp'] as num?)?.toDouble(),
        change: (j['change'] as num?)?.toDouble(),
        changePercent: (j['change_percent'] as num?)?.toDouble(),
        trend: (j['trend'] as String?) ?? 'stable',
        arrivals: j['arrivals'] as String?,
      );
}

class MandiRatesResponse {
  final MandiInfo mandi;
  final String date;
  final List<MandiRate> rates;

  const MandiRatesResponse({
    required this.mandi,
    required this.date,
    required this.rates,
  });

  factory MandiRatesResponse.fromJson(Map<String, dynamic> j) => MandiRatesResponse(
        mandi: MandiInfo.fromJson(j['mandi'] as Map<String, dynamic>),
        date: j['date'] as String,
        rates: (j['rates'] as List<dynamic>)
            .map((e) => MandiRate.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}

class MandiTrendData {
  final int commodityId;
  final String commodity;
  final String commodityEn;
  final String unit;
  final List<String> dates;
  final List<double> prices;
  final List<double> min;
  final List<double> max;
  final double? msp;
  final String? bestMonthHint;

  const MandiTrendData({
    required this.commodityId,
    required this.commodity,
    required this.commodityEn,
    required this.unit,
    required this.dates,
    required this.prices,
    required this.min,
    required this.max,
    this.msp,
    this.bestMonthHint,
  });

  factory MandiTrendData.fromJson(Map<String, dynamic> j) {
    final comm = j['commodity'] as Map<String, dynamic>;
    return MandiTrendData(
      commodityId: comm['id'] as int,
      commodity: comm['name_hi'] as String,
      commodityEn: comm['name'] as String,
      unit: comm['unit'] as String,
      dates: List<String>.from(j['dates'] as List),
      prices: (j['prices'] as List).map((e) => (e as num).toDouble()).toList(),
      min: (j['min'] as List).map((e) => (e as num).toDouble()).toList(),
      max: (j['max'] as List).map((e) => (e as num).toDouble()).toList(),
      msp: (j['msp'] as num?)?.toDouble(),
      bestMonthHint: j['best_month_hint'] as String?,
    );
  }
}

class NearbyMandi {
  final int id;
  final String name;
  final String nameHi;
  final String? city;
  final double distanceKm;
  final List<Map<String, dynamic>> preview;

  const NearbyMandi({
    required this.id,
    required this.name,
    required this.nameHi,
    this.city,
    required this.distanceKm,
    required this.preview,
  });

  factory NearbyMandi.fromJson(Map<String, dynamic> j) => NearbyMandi(
        id: j['id'] as int,
        name: j['name'] as String,
        nameHi: (j['name_hi'] as String?) ?? j['name'] as String,
        city: j['city'] as String?,
        distanceKm: (j['distance_km'] as num).toDouble(),
        preview: List<Map<String, dynamic>>.from(j['preview'] as List? ?? []),
      );
}

class MandiAlert {
  final int id;
  final String alertType; // 'above' | 'below'
  final double targetPrice;
  final bool isActive;
  final String? lastTriggered;
  final String commodity;
  final String commodityEn;
  final String unit;
  final String mandi;
  final String createdAt;

  const MandiAlert({
    required this.id,
    required this.alertType,
    required this.targetPrice,
    required this.isActive,
    this.lastTriggered,
    required this.commodity,
    required this.commodityEn,
    required this.unit,
    required this.mandi,
    required this.createdAt,
  });

  factory MandiAlert.fromJson(Map<String, dynamic> j) => MandiAlert(
        id: j['id'] as int,
        alertType: j['alert_type'] as String,
        targetPrice: (j['target_price'] as num).toDouble(),
        isActive: (j['is_active'] as int) == 1,
        lastTriggered: j['last_triggered'] as String?,
        commodity: j['commodity'] as String,
        commodityEn: j['commodity_en'] as String,
        unit: j['unit'] as String,
        mandi: j['mandi'] as String,
        createdAt: j['created_at'] as String,
      );
}
