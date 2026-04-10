import 'package:flutter/material.dart';
import 'package:fl_chart/fl_chart.dart';
import '../../data/models/mandi_model.dart';
import '../../data/services/mandi_service.dart';
import '../../core/constants/app_colors.dart';

/// Commodity detail — 30-day price trend chart, MSP reference, history table.
class CommodityDetailScreen extends StatefulWidget {
  const CommodityDetailScreen({
    super.key,
    required this.commodityId,
    required this.commodityName,
    required this.commodityEn,
    required this.mandiId,
  });

  final int    commodityId;
  final String commodityName;
  final String commodityEn;
  final int    mandiId;

  @override
  State<CommodityDetailScreen> createState() => _CommodityDetailScreenState();
}

class _CommodityDetailScreenState extends State<CommodityDetailScreen> {
  final _svc = MandiService();
  MandiTrendData? _trend;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final data = await _svc.getTrend(
      commodityId: widget.commodityId,
      mandiId:     widget.mandiId,
      days:        30,
    );
    if (mounted) {
      setState(() {
        _trend   = data;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(widget.commodityName, style: const TextStyle(fontSize: 16)),
            Text(widget.commodityEn,
                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.normal)),
          ],
        ),
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _trend == null || _trend!.dates.isEmpty
              ? const Center(child: Text('डेटा उपलब्ध नहीं है'))
              : _buildContent(isDark),
    );
  }

  Widget _buildContent(bool isDark) {
    final t = _trend!;
    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (t.bestMonthHint != null) _buildHintCard(t.bestMonthHint!),
          const SizedBox(height: 16),
          Text('30 दिन का मूल्य चार्ट',
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 15,
                color: isDark ? AppColors.textPrimaryDark : AppColors.textPrimaryLight,
              )),
          const SizedBox(height: 8),
          _buildChart(t, isDark),
          const SizedBox(height: 24),
          Text('ऐतिहासिक डेटा',
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 15,
                color: isDark ? AppColors.textPrimaryDark : AppColors.textPrimaryLight,
              )),
          const SizedBox(height: 8),
          _buildHistoryTable(t, isDark),
        ],
      ),
    );
  }

  Widget _buildHintCard(String hint) => Container(
        width: double.infinity,
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AppColors.accent.withAlpha(30),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: AppColors.accent.withAlpha(100)),
        ),
        child: Row(
          children: [
            const Text('💡', style: TextStyle(fontSize: 18)),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'सुझाव: पिछले साल $hint',
                style: const TextStyle(fontWeight: FontWeight.w500),
              ),
            ),
          ],
        ),
      );

  Widget _buildChart(MandiTrendData t, bool isDark) {
    final prices = t.prices;
    final msp    = t.msp;
    if (prices.isEmpty) return const SizedBox.shrink();

    final minY = (prices.reduce((a, b) => a < b ? a : b) * 0.97).floorToDouble();
    final maxY = (prices.reduce((a, b) => a > b ? a : b) * 1.03).ceilToDouble();

    final spots = List.generate(
      prices.length,
      (i) => FlSpot(i.toDouble(), prices[i]),
    );

    return Container(
      height: 220,
      padding: const EdgeInsets.only(right: 16, top: 16),
      child: LineChart(
        LineChartData(
          minY: minY,
          maxY: maxY,
          gridData: FlGridData(
            show: true,
            getDrawingHorizontalLine: (_) => FlLine(
              color: Colors.grey.withAlpha(40),
              strokeWidth: 1,
            ),
            getDrawingVerticalLine: (_) => FlLine(
              color: Colors.grey.withAlpha(20),
              strokeWidth: 1,
            ),
          ),
          borderData: FlBorderData(show: false),
          titlesData: FlTitlesData(
            leftTitles: AxisTitles(
              sideTitles: SideTitles(
                showTitles: true,
                reservedSize: 52,
                getTitlesWidget: (val, _) => Text(
                  '₹${val.toInt()}',
                  style: TextStyle(
                    fontSize: 10,
                    color: isDark ? AppColors.textSecondaryDark : AppColors.textSecondaryLight,
                  ),
                ),
              ),
            ),
            rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
            topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
            bottomTitles: AxisTitles(
              sideTitles: SideTitles(
                showTitles: true,
                reservedSize: 22,
                interval: (prices.length / 5).ceilToDouble(),
                getTitlesWidget: (val, _) {
                  final idx = val.toInt();
                  if (idx < 0 || idx >= t.dates.length) return const SizedBox.shrink();
                  final d = t.dates[idx];
                  final parts = d.split('-');
                  final label = parts.length == 3 ? '${parts[2]}/${parts[1]}' : d;
                  return Text(label,
                      style: TextStyle(
                        fontSize: 9,
                        color: isDark ? AppColors.textSecondaryDark : AppColors.textSecondaryLight,
                      ));
                },
              ),
            ),
          ),
          lineBarsData: [
            // Price line
            LineChartBarData(
              spots: spots,
              isCurved: true,
              color: AppColors.primary,
              barWidth: 2.5,
              dotData: const FlDotData(show: false),
              belowBarData: BarAreaData(
                show: true,
                color: AppColors.primary.withAlpha(30),
              ),
            ),
            // MSP reference line
            if (msp != null)
              LineChartBarData(
                spots: [FlSpot(0, msp), FlSpot((prices.length - 1).toDouble(), msp)],
                isCurved: false,
                color: Colors.orange,
                barWidth: 1.5,
                dashArray: [6, 4],
                dotData: const FlDotData(show: false),
              ),
          ],
          extraLinesData: ExtraLinesData(
            horizontalLines: msp != null
                ? [
                    HorizontalLine(
                      y: msp,
                      color: Colors.transparent,
                      label: HorizontalLineLabel(
                        show: true,
                        alignment: Alignment.topRight,
                        labelResolver: (_) => 'MSP ₹${msp.toInt()}',
                        style: const TextStyle(
                          color: Colors.orange,
                          fontSize: 10,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                  ]
                : [],
          ),
        ),
      ),
    );
  }

  Widget _buildHistoryTable(MandiTrendData t, bool isDark) {
    final headerColor = isDark ? AppColors.surfaceDark : const Color(0xFFF0F0F0);
    final borderColor = isDark ? Colors.white12 : Colors.black12;

    return Table(
      border: TableBorder.all(color: borderColor, width: 0.5),
      columnWidths: const {
        0: FlexColumnWidth(2),
        1: FlexColumnWidth(1.5),
        2: FlexColumnWidth(1.5),
        3: FlexColumnWidth(1.5),
      },
      children: [
        TableRow(
          decoration: BoxDecoration(color: headerColor),
          children: ['तारीख', 'न्यूनतम', 'अधिकतम', 'मोडल']
              .map((h) => Padding(
                    padding: const EdgeInsets.all(8),
                    child: Text(h,
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                  ))
              .toList(),
        ),
        ...List.generate(t.dates.length, (i) {
          final idx = t.dates.length - 1 - i; // newest first
          return TableRow(
            children: [
              _cell(t.dates[idx]),
              _cell('₹${t.min[idx].toStringAsFixed(0)}'),
              _cell('₹${t.max[idx].toStringAsFixed(0)}'),
              _cell('₹${t.prices[idx].toStringAsFixed(0)}',
                  bold: true),
            ],
          );
        }),
      ],
    );
  }

  Widget _cell(String text, {bool bold = false}) => Padding(
        padding: const EdgeInsets.all(8),
        child: Text(text,
            style: TextStyle(
              fontSize: 12,
              fontWeight: bold ? FontWeight.bold : FontWeight.normal,
            )),
      );
}
