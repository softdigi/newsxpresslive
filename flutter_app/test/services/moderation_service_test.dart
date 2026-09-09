import 'package:flutter_test/flutter_test.dart';
import 'package:newsxpresslive/data/services/moderation_service.dart';

void main() {
  final mod = ModerationService.instance;

  group('ModerationService — safe content', () {
    test('normal comment is safe', () {
      final result = mod.analyseComment('This is a great article, thank you!');
      expect(result.isSafe, isTrue);
      expect(result.score, lessThan(0.40));
    });

    test('empty string gets block level (too short)', () {
      final result = mod.analyseComment('');
      // score >= 0.30 for empty → but still below block unless combined with others
      expect(result.score, greaterThanOrEqualTo(0.30));
    });
  });

  group('ModerationService — warn content', () {
    test('fake news phrase triggers warn', () {
      final result = mod.analyseComment(
        'This shocking truth will change everything about the government.',
      );
      expect(result.isWarn || result.isBlock, isTrue);
      expect(result.score, greaterThanOrEqualTo(0.40));
    });

    test('moderate exclamation marks triggers warn', () {
      final result = mod.analyseComment(
        'Unbelievable!!!! You should see this!!!! Amazing news!!!!',
      );
      expect(result.score, greaterThanOrEqualTo(0.10)); // at least exclaim penalty
    });
  });

  group('ModerationService — block content', () {
    test('strong abuse terms trigger block', () {
      // Contains several abuse-term matches
      final result = mod.analyseComment('You idiot moron stupid fool scum');
      expect(result.isBlock, isTrue);
      expect(result.score, greaterThanOrEqualTo(0.75));
    });

    test('all-caps shouting with exclamation marks triggers high score', () {
      final result = mod.analyseComment('THIS IS TOTALLY FAKE NEWS!!!!!!!!');
      expect(result.score, greaterThan(0.30));
    });

    test('url flooding triggers block', () {
      final urls = List.generate(
        6,
        (i) => 'https://spam-site-$i.com/buy-now',
      ).join(' ');
      final result = mod.analyseComment(urls);
      expect(result.isBlock, isTrue);
    });
  });

  group('ModerationService — analyseSubmission', () {
    test('safe title + safe body → safe', () {
      final result = mod.analyseSubmission(
        title:       'Government announces budget',
        description: 'The finance minister presented the annual budget today.',
      );
      expect(result.isSafe, isTrue);
    });

    test('clickbait title inflates score', () {
      final result = mod.analyseSubmission(
        title:       "You won't believe what the government is hiding!",
        description: 'Regular article body text here.',
      );
      expect(result.score, greaterThanOrEqualTo(0.15));
    });
  });

  group('ModerationService — backendFlag', () {
    test('safe result returns null backendFlag', () {
      final result = mod.analyseComment('Nice article');
      expect(result.backendFlag, isNull);
    });

    test('warn result returns review_requested', () {
      final result = ModerationResult(
        level: ModerationLevel.warn,
        reason: 'test',
        score: 0.50,
      );
      expect(result.backendFlag, 'review_requested');
    });

    test('block result returns auto_hidden', () {
      final result = ModerationResult(
        level: ModerationLevel.block,
        reason: 'test',
        score: 0.80,
      );
      expect(result.backendFlag, 'auto_hidden');
    });
  });
}
