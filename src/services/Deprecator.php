<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\services;

use Craft;
use craft\app\helpers\DateTimeHelper;
use craft\app\helpers\JsonHelper;
use craft\app\models\DeprecationError as DeprecationErrorModel;
use yii\base\Component;

/**
 * Class Deprecator service.
 *
 * An instance of the Deprecator service is globally accessible in Craft via [[Application::deprecator `Craft::$app->deprecator`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Deprecator extends Component
{
	// Properties
	// =========================================================================

	/**
	 * @var string
	 */
	private static $_tableName = 'deprecationerrors';

	/**
	 * @var array
	 */
	private $_fingerprints = [];

	/**
	 * @var
	 */
	private $_allLogs;

	// Public Methods
	// =========================================================================

	/**
	 * Logs a new deprecation error.
	 *
	 * @param string $key
	 * @param string $message
	 *
	 * @return bool
	 */
	public function log($key, $message)
	{
		$request = Craft::$app->getRequest();

		$log = new DeprecationErrorModel();

		$log->key            = $key;
		$log->message        = $message;
		$log->lastOccurrence = DateTimeHelper::currentTimeForDb();
		$log->template       = (!$request->getIsConsoleRequest() && $request->getIsSiteRequest() ? Craft::$app->templates->getRenderingTemplate() : null);

		// Everything else requires the stack trace
		$this->_populateLogWithStackTraceData($log);

		// Don't log the same key/fingerprint twice in the same request
		if (!isset($this->_fingerprints[$log->key]) || !in_array($log->fingerprint, $this->_fingerprints[$log->key]))
		{
			Craft::$app->getDb()->createCommand()->insertOrUpdate(static::$_tableName, [
				'key'            => $log->key,
				'fingerprint'    => $log->fingerprint
			], [
				'lastOccurrence' => DateTimeHelper::formatTimeForDb($log->lastOccurrence),
				'file'           => $log->file,
				'line'           => $log->line,
				'class'          => $log->class,
				'method'         => $log->method,
				'template'       => $log->template,
				'templateLine'   => $log->templateLine,
				'message'        => $log->message,
				'traces'         => JsonHelper::encode($log->traces),
			]);

			$this->_fingerprints[$key][] = $log->fingerprint;
		}

		return true;
	}

	/**
	 * Returns the total number of deprecation errors that have been logged.
	 *
	 * @return int
	 */
	public function getTotalLogs()
	{
		return Craft::$app->getDb()->createCommand()
			->from(static::$_tableName)
			->count('id');
	}

	/**
	 * Get 'em all.
	 *
	 * @param int $limit
	 *
	 * @return array
	 */
	public function getLogs($limit = 100)
	{
		if (!isset($this->_allLogs))
		{
			$result = Craft::$app->getDb()->createCommand()
				->select('*')
				->from(static::$_tableName)
				->limit($limit)
				->order('lastOccurrence desc')
				->queryAll();

			$this->_allLogs = DeprecationErrorModel::populateModels($result);
		}

		return $this->_allLogs;
	}

	/**
	 * Returns a log by its ID.
	 *
	 * @param $logId
	 *
	 * @return DeprecationErrorModel|null
	 */
	public function getLogById($logId)
	{
		$log = Craft::$app->getDb()->createCommand()
			->select('*')
			->from(static::$_tableName)
			->where('id = :logId', [':logId' => $logId])
			->queryRow();

		if ($log)
		{
			return new DeprecationErrorModel($log);
		}
	}

	/**
	 * Deletes a log by its ID.
	 *
	 * @param $id
	 *
	 * @return bool
	 */
	public function deleteLogById($id)
	{
		$affectedRows = Craft::$app->getDb()->createCommand()->delete(static::$_tableName, ['id' => $id]);
		return (bool) $affectedRows;
	}

	/**
	 * Deletes all logs.
	 *
	 * @return bool
	 */
	public function deleteAllLogs()
	{
		$affectedRows = Craft::$app->getDb()->createCommand()->delete(static::$_tableName);
		return (bool) $affectedRows;
	}

	// Private Methods
	// =========================================================================

	/**
	 * Populates a DeprecationErrorModel with data pulled from the PHP stack trace.
	 *
	 * @param DeprecationErrorModel $log
	 *
	 * @return null
	 */
	private function _populateLogWithStackTraceData(DeprecationErrorModel $log)
	{
		// Get the stack trace, but skip the first one, since it's just the call to this private function
		$traces = debug_backtrace();
		array_shift($traces);

		// Set the basic stuff
		$log->file   = $traces[0]['file'];
		$log->line   = $traces[0]['line'];
		$log->class  = !empty($traces[1]['class'])    ? $traces[1]['class']    : null;
		$log->method = !empty($traces[1]['function']) ? $traces[1]['function'] : null;

		/* HIDE */
		$foundPlugin = false;
		$pluginsPath = realpath(Craft::$app->path->getPluginsPath()).'/';
		$pluginsPathLength = strlen($pluginsPath);
		/* end HIDE */

		$request = Craft::$app->getRequest();
		$isTemplateRendering = (!$request->getIsConsoleRequest() && $request->getIsSiteRequest() && Craft::$app->templates->isRendering());

		if ($isTemplateRendering)
		{
			// We'll figure out the line number later
			$log->fingerprint = $log->template;

			$foundTemplate = false;
		}
		else
		{
			$log->fingerprint = $log->class.($log->class && $log->line ? ':'.$log->line : '');
		}

		$logTraces = [];

		foreach ($traces as $trace)
		{
			$logTrace = [
				'objectClass' => (!empty($trace['object']) ? get_class($trace['object']) : null),
				'file'        => (!empty($trace['file'])     ? $trace['file'] : null),
				'line'        => (!empty($trace['line'])     ? $trace['line'] : null),
				'class'       => (!empty($trace['class'])    ? $trace['class'] : null),
				'method'      => (!empty($trace['function']) ? $trace['function'] : null),
				'args'        => (!empty($trace['args'])     ? $this->_argsToString($trace['args']) : null),
			];

			// Is this a template?
			if (isset($trace['object']) && $trace['object'] instanceof \Twig_Template && 'Twig_Template' !== get_class($trace['object']) && strpos($trace['file'], 'compiled_templates') !== false)
			{
				$template = $trace['object'];

				// Get the original (uncompiled) template name.
				$logTrace['template'] = $template->getTemplateName();

				// Guess the line number
				foreach ($template->getDebugInfo() as $codeLine => $templateLine)
				{
					if ($codeLine <= $trace['line'])
					{
						$logTrace['templateLine'] = $templateLine;

						// Save that to the main log info too?
						if ($isTemplateRendering && !$foundTemplate)
						{
							$log->templateLine = $templateLine;
							$log->fingerprint .= ':'.$templateLine;
							$foundTemplate = true;
						}

						break;
					}
				}

				/* HIDE */
				if ($isTemplateRendering && !$foundTemplate)
				{
					// Is this a plugin's template?
					$request = Craft::$app->getRequest();
					if (!$foundPlugin && !$request->getIsConsoleRequest() && $request->getIsCpRequest() && $logTrace['template'])
					{
						$firstSeg = array_shift(explode('/', $logTrace['template']));

						if (Craft::$app->plugins->getPlugin($firstSeg))
						{
							$log->plugin = $firstSeg;
							$foundPlugin = true;
						}
					}

					$foundTemplate = true;
				}
				/* end HIDE */
			}

			/* HIDE */
			// Is this a plugin's file?
			else if (!$foundPlugin && $logTrace['file'])
			{
				$filePath = realpath($logTrace['file']).'/';

				if (strncmp($pluginsPath, $logTrace['file'], $pluginsPathLength) === 0)
				{
					$remainingFilePath = substr($filePath, $pluginsPathLength);
					$firstSeg = array_shift(explode('/', $remainingFilePath));

					if (Craft::$app->plugins->getPlugin($firstSeg))
					{
						$log->plugin = $firstSeg;
						$foundPlugin = true;
					}
				}
			}
			/* end HIDE */

			$logTraces[] = $logTrace;
		}

		$log->traces = $logTraces;
	}

	/**
	 * Converts an array of method arguments to a string.
	 *
	 * Adapted from [[\CErrorHandler::argumentsToString()]], but this one's less destructive
	 *
	 * @param $args array
	 *
	 * @return string
	 */
	private function _argsToString($args)
	{
		$strArgs = [];
		$isAssoc = ($args !== array_values($args));

		$count = 0;

		foreach($args as $key => $value)
		{
			// Cap it off at 5
			$count++;

			if ($count == 5)
			{
				$strValue = '...';
				break;
			}

			if (is_object($value))
			{
				$strValue = get_class($value);
			}
			else if (is_bool($value))
			{
				$strValue = $value ? 'true' : 'false';
			}
			else if (is_string($value))
			{
				if (strlen($value) > 64)
				{
					$strValue = '"'.substr($value, 0, 64).'..."';
				}
				else
				{
					$strValue = '"'.$value.'"';
				}
			}
			else if (is_array($value))
			{
				$strValue = 'array('.$this->_argsToString($value).')';
			}
			else if ($value === null)
			{
				$strValue = 'null';
			}
			else if (is_resource($value))
			{
				$strValue = 'resource';
			}
			else
			{
				$strValue = $value;
			}

			if (is_string($key))
			{
				$strArgs[] = '"'.$key.'" => '.$strValue;
			}
			else if ($isAssoc)
			{
				$strArgs[] = $key.' => '.$strValue;
			}
			else
			{
				$strArgs[] = $strValue;
			}

			if ($count == 5)
			{
				break;
			}
		}

		return implode(', ', $strArgs);
	}
}
