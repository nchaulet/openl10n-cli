<?php

namespace Openl10n\Cli\Command;

use Openl10n\Sdk\Model\Project;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class InitCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('init')
            ->setDefinition(array(
                new InputOption('project', null, InputOption::VALUE_REQUIRED, 'Slug of the project'),
                new InputOption('hostname', null, InputOption::VALUE_REQUIRED, 'Set server hostname'),
                new InputOption('port', null, InputOption::VALUE_REQUIRED, 'Specific port for the server', 80),
                new InputOption('ssl', null, InputOption::VALUE_NONE, 'Use SSL'),
                new InputOption('username', null, InputOption::VALUE_REQUIRED, 'Set server user'),
                new InputOption('password', null, InputOption::VALUE_REQUIRED, 'Set server password'),
                new InputOption('files', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Pattern of translation ressources, e.g. "locales/<locale>.yml"')
            ))
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dialog = $this->getHelperSet()->get('dialog');
        $options = $input->getOptions();

        if (null == $options['project']) {
            $project = basename(realpath('.'));
            $options['project'] = $dialog->ask($output, "<info>Project's slug</info> [<comment>$project</comment>]: ", $project);
        }
        if (null == $options['hostname']) {
            $options['hostname'] = $dialog->ask($output, '<info>Hostname</info> [<comment>openl10n.dev</comment>]: ', 'openl10n.dev');
        }
        if (false == $options['ssl']) {
            $options['ssl'] = $dialog->askConfirmation($output, '<info>Enable ssl</info> [<comment>no</comment>]? ', false);
        }
        if (80 == $options['port']) {
            $port = $options['ssl'] ? 443 : 80;
            $options['port'] = $dialog->askAndValidate(
                $output,
                "<info>Port</info> [<comment>$port</comment>]: ",
                function ($answer) {
                    if (!is_int($answer) && !ctype_digit($answer)) {
                        throw new \RuntimeException('The port must be an integer.');
                    }
                    return $answer;
                },
                false,
                $port
            );
        }
        if (null == $options['username']) {
            $user = get_current_user();
            $options['username'] = $dialog->ask($output, "<info>Username</info> [<comment>$user</comment>]: ", $user);
        }
        if (null == $options['password']) {
            $options['password'] = $dialog->askHiddenResponseAndValidate(
                $output,
                '<info>Password</info> []: ',
                function ($answer) {
                    if ('' == trim($answer)) {
                        throw new \RuntimeException('The password can not be empty.');
                    }
                    return $answer;
                },
                false,
                false
            );
        }

        if (null == $options['files']) {
            $output->writeln('');
            while (null !== $file = $dialog->ask($output, '<info>Pattern file</info> []: ')) {
                if (false !== $file) {
                    $options['files'][] = $file;
                }
            }
        }

        $config = array(
            'server' => array(
               'hostname' => $options['hostname'],
               'port' => (int) $options['port'],
               'use_ssl' => (bool) $options['ssl'],
               'username' => $options['username'],
               'password' => $options['password'],
            ),
            'project' => $options['project'],
        );
        if (null !== $options['files']) {
            foreach ($options['files'] as $file) {
                $config['files'][] = ['pattern' => $file];
            }
        }
        if ((80 == $options['port'] && false == $options['ssl'])
            || (443 == $options['port'] && true == $options['ssl'])
        ) {
            unset($config['server']['port']);
        }
        if (false == $options['ssl']) {
            unset($config['server']['use_ssl']);
        }

        file_put_contents('./openl10n.yml', Yaml::dump($config, 3));

        $output->writeln('');
        if ($dialog->askConfirmation($output, '<info>Would you like to create the project</info> [<comment>yes</comment>]? ', true)) {
            $project = new Project($config['project']);

            $defaultName = ucfirst($project->getSlug());
            $name = $dialog->ask($output, "<info>Project's name</info> [<comment>$defaultName</comment>]: ", $defaultName);
            $project->setName($name);

            $defaultLocale = $dialog->ask($output, "<info>Default locale</info> [<comment>en</comment>]: ", 'en');
            $project->setDefaultLocale($defaultLocale);

            $projectApi = $this->get('openl10n.api')->getEntryPoint('project');
            try {
                $projectApi->create($project);
            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return 1;
            }
        }
    }
}
