## Critical Development Instructions

**このセクションの指示は非常に重要です。必ず守ってください。**

このプロジェクトはPHPで書かれたCコンパイラの実装です。
このプロジェクトの目的は、chibicc と slimcc というCコンパイラの実装を段階的に再現することです。
現在は chibicc のすべての実装を完了し、slimcc の実装を段階的に進めています。

### Basic Guidelines

エージェントは基本的なソフトウェアの実装パターン・アルゴリズム等に詳しいものの、プロジェクト固有のコンテキスト把握を苦手としています。コンテキストをはっきりさせる必要がある場合は、ユーザに質問してください。  

### Communication

思考過程などログの出力や、Todoの内容は日本語でしてください。  

### Code Quality

タスクの最後には、以下のコマンドでテストを実施しすべてパスすることを必ず確認してください。

```bash
$ make ctest                         # Cで書いたテスト
```

エラーになった場合は、エラーの内容と参考にする slimcc のコードをもとに適切な修正をおこなってください。

### Coding Standards

* プログラム中のコメントは英語で記述してください。
* コードはすべて PSR-12 に従って書いてください。
* 条件文の中の or, and はそれぞれ ||, && でなく or, and を使用してください。

### Testing Policy

テストコードは slimcc の対象コミットに含まれるものと同じものを使用します。
テストコードを変更することは禁じられています。
デバッグのためにテストコードを追加する時は ctests/test 配下に追加してください。

テストコードをすべて実行する場合はプロジェクトルートで make ctest としてください。
テストコードを単体で実行する場合はプロジェクトルートで make ctest file=filename.c としてください。
phpコマンドや slimcc を実行する場合は必ず docker compose run --rm php php pcc.php のように実行し、Dockerコンテナの中で実行してください。

実装が完了したら必ず以下を確認してください。

* テストコードが slimcc と完全に一致していること
* make ctest を実行してすべてのテストがパスすること

### External Dependencies

Composer や npm で外部の依存を新しく増やしたくなったときは、composer コマンドや npm コマンドを実行する代わりに、そのライブラリの選択が適切である理由をユーザに説明し、導入の許可を得てください。許可なしに外部依存を増やしたり減らしたり更新したりすることは禁じられています。

### 参考にするコード

このプロジェクトは slimcc のgitコミットと1:1対応させて、ステップバイステップで開発します。
chibicc は ./chibicc ディレクトリにcloneしてあります。
slimcc は ./slimcc ディレクトリにcloneしてあります。
最初に slimcc からテストコードをpccに反映してから作業を開始してください。
slimcc のコードをよく見て慎重に設計し、設計が slimcc と離れない様に設計してください。
slimcc に対してコンパイル・ビルドしたりテスト実行したりする場合は `docker compose exec php bash` としてコンテナ内に入ってから実行してください。
slimcc を実行する時もコンテナ内に入ってから実行してください。

## Essential Development Commands

### Local Development

すべてプロジェクトルートをカレントフォルダとして実行してください。

```bash
$ make up                                     # 開発環境の起動
$ make ctest                                  # テストをすべて実行
$ make ctest file=foo.c                       # テストを1ファイルのみ実行
$ docker compose run --rm php pcc             # pcc.phpを単体で実行
```

## ディレクトリ構造

```
├ /                           # プロジェクトルート。make ctest はこのディレクトリをカレントディレクトリとして実行する。
├ /chibicc/                    # chibiccのソースコードと .git ディレクトリ。chibicc のソースコードに対する git status や git diff はこのディレクトリをカレントディレクトリとして実行する。
├ /slimcc/                     # slimccのソースコードと .git ディレクトリ。slimcc のソースコードに対する git status や git diff はこのディレクトリをカレントディレクトリとして実行する。
├ /slimcc/test                 # slimccのテストコード。ここからコピーして使用する。 
├ /ctest/test                  # pccのテストコード。テストはここに追加する。
├ /src/                        # pccのソースコード。開発ではここを編集する。
```
