## Critical Development Instructions

**このセクションの指示は非常に重要です。必ず守ってください。**

### Basic Guidelines

エージェントは基本的なソフトウェアの実装パターン・アルゴリズム等に詳しいものの、プロジェクト固有のコンテキスト把握を苦手としています。コンテキストをはっきりさせる必要がある場合は、ユーザに質問してください。
思考過程などログの出力や、Todoの内容は日本語でしてください。

### Code Quality

タスクの最後には、以下のコマンドが正常終了することを必ず確認してください。

```bash
$ make ctest                         # Cで書いたテスト
```

エラーになった場合は、エラーの内容から自動的に適切な修正をおこなってください。

### Git Commit Policy

コミットは以下のルールに従ってください。

* コミットメッセージは対応するchibiccのコミットメッセージと同じ内容にしてください。
* chibiccのコミットメッセージに文字を追加することは禁止されています。

### Coding Standards

コードはすべて PSR-12 に従って書いてください。
ただし、以下を優先してください。

* 条件文の中の or, and はそれぞれ ||, && でなく or, and を使用してください。

### Testing Policy

テストコードは chibicc と同じものを使用します。
テストコードを変更することは禁じられています。
デバッグのためにテストコードを追加する時は ctest/TestCase 配下に追加し、 make ctest file=filename.c の様に実行してください。
phpコマンドを実行する場合は必ず docker compose run --rm php php pcc.php のように実行してください。

### External Dependencies

Composer や npm で外部の依存を新しく増やしたくなったときは、composer コマンドや npm コマンドを実行する代わりに、そのライブラリの選択が適切である理由をユーザに説明し、導入の許可を得てください。許可なしに外部依存を増やしたり減らしたり更新したりすることは禁じられています。

### 参考にするコード

このプロジェクトは chibicc のgitコミットと1:1対応させて、ステップバイステップで開発します。
chibicc は ./chibicc ディレクトリにcloneしてあります。

## Essential Development Commands

### Local Development

すべてプロジェクトルートをカレントフォルダとして実行してください。

```bash
$ make up                                     # 開発環境の起動
$ make ctest                                  # テストをすべて実行
$ make ctest file=foo.c                       # テストを1ファイルのみ実行
$ docker compose run --rm php php pcc.php     # pcc.phpを単体で実行
```

## ディレクトリ構造

```
├ chibicc/                    # chibiccのソースコードと .git ディレクトリ
├ ctest/                      # テストコード。テストはここに追加する。
├ src/                        # pccのソースコード。開発ではここを編集する。
```
