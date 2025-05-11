<?php

declare(strict_types=1);

namespace DaggerModule;

use Dagger\Attribute\DaggerFunction;
use Dagger\Attribute\DaggerObject;
use Dagger\Attribute\DefaultPath;
use Dagger\Attribute\Doc;
use Dagger\Container;
use Dagger\Directory;

use function Dagger\dag;

#[DaggerObject]
class HelloDagger
{
  #[DaggerFunction]
  #[Doc('Publish the application container after building and testing it on-the-fly')]
  public function publish(#[DefaultPath('/')] Directory $source): string
  {
    $this->test($source);

    return $this->build($source)->publish('ttl.sh/hello-dagger-' . rand(0, 10000000));
  }

  #[DaggerFunction]
  #[Doc('Build the application container')]
  public function build(#[DefaultPath('/')] Directory $source): Container
  {
    $build = $this->buildEnv($source)
      ->withExec(['npm', 'run', 'build'])
      ->directory('./dist');

    return dag()
      ->container()
      ->from('nginx:1.25-alpine')
      ->withDirectory('/usr/share/nginx/html', $build)
      ->withExposedPort(80);
  }

  #[DaggerFunction]
  #[Doc('Return the result of running unit tests')]
  public function test(#[DefaultPath('/')] Directory $source): string
  {
    return $this->buildEnv($source)
      ->withExec(['npm', 'run', 'test:unit', 'run'])
      ->stdout();
  }

  #[DaggerFunction]
  #[Doc('Build a ready-to-use development environment')]
  public function buildEnv(#[DefaultPath('/')] Directory $source): Container
  {
    $nodeCache = dag()->cacheVolume('node');

    return dag()
      ->container()
      ->from('node:21-slim')
      ->withDirectory('/src', $source)
      ->withMountedCache('/root/.npm', $nodeCache)
      ->withWorkdir('/src')
      ->withExec(['npm', 'install']);
  }

  #[DaggerFunction]
  #[Doc('A coding agent for developing new features')]
  public function develop(
    #[Doc('Assignment to complete')] string $assignment,
    #[Doc('Source directory to develop')] #[DefaultPath('/')] Directory $source
  ): Directory {
    // Environment with agent inputs and outputs
    $environment = dag()
      ->env(privileged: true)
      ->withStringInput('assignment', $assignment, 'the assignment to complete')
      ->withWorkspaceInput(
        'workspace',
        dag()->workspace($source),
        'the workspace with tools to edit code'
      )
      ->withWorkspaceOutput('completed', 'the workspace with the completed assignment');

    // Detailed prompt stored in markdown file
    $promptFile = dag()->currentModule()->source()->file('develop_prompt.md');

    // Put it all together to form the agent
    $work = dag()->llm()->withEnv($environment)->withPromptFile($promptFile);

    // Get the output from the agent
    $completed = $work->env()->output('completed')->asWorkspace();
    $completedDirectory = $completed->getSource()->withoutDirectory('node_modules');

    // Make sure the tests really pass
    $this->test($completedDirectory);

    // Return the Directory with the assignment completed
    return $completedDirectory;
  }

  #[DaggerFunction]
  #[Doc('Develop with a Github issue as the assignment and open a pull request')]
  public function developIssue(
    #[Doc('Github Token with permissions to write issues and contents')] Secret $githubToken,
    #[Doc('Github issue number')] int $issueID,
    #[Doc('Github repository url')] string $repository,
    #[Doc('Source directory to develop')] #[DefaultPath('/')] Directory $source
  ): string {
    // Get the Github issue
    $issueClient = dag()->githubIssue(['token' => $githubToken]);
    $issue = $issueClient->read($repository, $issueID);

    // Get information from the Github issue
    $assignment = $issue->body();

    // Solve the issue with the Develop agent
    $feature = $this->develop($assignment, $source);

    // Open a pull request
    $title = $issue->title();
    $url = $issue->url();
    $body = $assignment . "\n\nCloses " . $url;
    $pr = $issueClient->createPullRequest($repository, $title, $body, $feature);

    return $pr->url();
  }
}
