## Critical Development Instructions

**このセクションの指示は非常に重要です。必ず守ってください。**

### Basic Guidelines

エージェントは基本的なソフトウェアの実装パターン・アルゴリズム等に詳しいものの、プロジェクト固有のコンテキスト把握を苦手としています。コンテキストをはっきりさせる必要がある場合は、ユーザに質問してください。  

### Communication

思考過程などログの出力や、Todoの内容は日本語でしてください。  
ユーザの入力を待つ時は、その前に以下のコマンドを実行して通知を出し、ユーザに知らせてください。
```bash
echo "Claude Code: Waiting user input."|/opt/homebrew/bin/terminal-notifier
```

### Code Quality

タスクの最後には、以下のコマンドでテストを実施しすべてパスすることを必ず確認してください。

```bash
$ make ctest                         # Cで書いたテスト
```

エラーになった場合は、エラーの内容から自動的に適切な修正をおこなってください。

### Git Commit Policy

実装が終わってテストがすべてパスしたらGitコミットを作ってください。
コミットメッセージは対応するchibiccのコミットメッセージと完全に同じ内容にしてください。
余計な装飾や追加の文字列を追加することは禁じられています。

### Coding Standards

* プログラム中のコメントは英語で記述してください。
* コードはすべて PSR-12 に従って書いてください。
* 条件文の中の or, and はそれぞれ ||, && でなく or, and を使用してください。

### Testing Policy

テストコードは chibicc の対象コミットに含まれるものと同じものを使用します。
テストコードを変更することは禁じられています。
デバッグのためにテストコードを追加する時は ctests/TestCase 配下に追加してください。
テストコードを単体で実行する場合は make ctest file=filename.c の様に実行してください。
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
├ /                           # プロジェクトルート。make ctest はこのディレクトリをカレントディレクトリとして実行する。
├ chibicc/                    # chibiccのソースコードと .git ディレクトリ。chibiccのソースコードに対する git status や git diff はこのディレクトリをカレントディレクトリとして実行する。
├ ctest/TestCase              # テストコード。テストはここに追加する。
├ src/                        # pccのソースコード。開発ではここを編集する。
```
