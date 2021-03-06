<?php
/**
 * WP_Framework_Mail Classes Models Mail
 *
 * @author Technote
 * @copyright Technote All Rights Reserved
 * @license http://www.opensource.org/licenses/gpl-2.0.php GNU General Public License, version 2
 * @link https://technote.space
 */

namespace WP_Framework_Mail\Classes\Models;

if ( ! defined( 'WP_CONTENT_FRAMEWORK' ) ) {
	exit;
}

use PHPMailer;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;
use WP_Error;
use WP_Framework_Core\Traits\Hook;
use WP_Framework_Core\Traits\Singleton;
use WP_Framework_Mail\Traits\Package;
use WP_Framework_Presenter\Traits\Presenter;

/**
 * Class Mail
 * @package WP_Framework_Mail\Classes\Models
 */
class Mail implements \WP_Framework_Core\Interfaces\Singleton, \WP_Framework_Core\Interfaces\Hook, \WP_Framework_Presenter\Interfaces\Presenter {

	use Singleton, Hook, Presenter, Package;

	/**
	 * @var bool $is_sending
	 */
	private $is_sending = false;

	/**
	 * @param string $to
	 * @param string $subject
	 * @param string|array $body
	 * @param string|false $text
	 *
	 * @return bool
	 */
	public function send( $to, $subject, $body, $text = false ) {
		if ( $this->is_sending || empty( $to ) || empty( $subject ) || ( empty( $body ) && empty( $text ) ) ) {
			return false;
		}

		$subject = str_replace( [ "\r\n", "\r", "\n" ], '', $subject );
		$this->remove_special_space( $subject );
		$this->remove_special_space( $body );

		if ( ! is_array( $body ) ) {
			$body = [ 'text/html' => $body ];
		}
		if ( ! empty( $text ) ) {
			$this->remove_special_space( $text );
			$body['text/plain'] = $text;
		}

		list( $messages, $content_type ) = $this->parse_body( $body, $subject );

		if ( empty( $messages ) ) {
			return false;
		}
		if ( count( $messages ) > 1 ) {
			$content_type = 'multipart/alternative';
		}

		$this->fix_content_type( $messages, $content_type );

		// suppress error
		$this->app->input->set_server( 'SERVER_NAME', $this->app->input->server( 'SERVER_NAME', '' ) );

		// is sending
		$this->is_sending = true;

		$result = wp_mail( $to, $subject, reset( $messages ) );

		$this->is_sending = false;

		return $result;
	}

	/**
	 * @param array $body
	 * @param string $subject
	 *
	 * @return array
	 */
	private function parse_body( $body, $subject ) {
		$css          = new CssToInlineStyles();
		$messages     = [];
		$content_type = 'text/html';
		foreach ( $body as $type => $message ) {
			if ( is_array( $message ) ) {
				$message = reset( $messages );
			}
			if ( 'text/html' === $type ) {
				$message = $this->get_view( 'common/mail', [
					'subject' => $subject,
					'body'    => $message,
				] );
				$message = $css->convert( $message );
				$message = preg_replace( '/<\s*style.*?>[\s\S]*<\s*\/style\s*>/', '', $message );
			} elseif ( 'text/plain' !== $type ) {
				continue;
			}
			$messages[ $type ] = $message;
			$content_type      = $type;
		}

		return [ $messages, $content_type ];
	}

	/**
	 * @param array $messages
	 * @param string $content_type
	 */
	private function fix_content_type( $messages, $content_type ) {
		// このチケットがマージされたら以下の処理は不要
		// https://core.trac.wordpress.org/ticket/15448

		add_action( 'phpmailer_init', $set_phpmailer = function ( $phpmailer ) use ( &$set_phpmailer, $messages, $content_type ) {
			/** @var PHPMailer $phpmailer */
			remove_action( 'phpmailer_init', $set_phpmailer );
			$phpmailer->Body    = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$phpmailer->AltBody = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			foreach ( $messages as $type => $message ) {
				if ( 'text/html' === $type ) {
					$phpmailer->Body = $message; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				} elseif ( 'text/plain' === $type ) {
					$phpmailer->AltBody = $message; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				}
			}
			$phpmailer->ContentType = $content_type; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} );
	}

	/**
	 * @param $str string|array
	 */
	private function remove_special_space( &$str ) {
		if ( is_array( $str ) ) {
			foreach ( array_keys( $str ) as $key ) {
				$this->remove_special_space( $str[ $key ] );
			}
		} else {
			$special_space = [
				"\xC2\xA0",
				"\xE1\xA0\x8E",
				"\xE2\x80\x80",
				"\xE2\x80\x81",
				"\xE2\x80\x82",
				"\xE2\x80\x83",
				"\xE2\x80\x84",
				"\xE2\x80\x85",
				"\xE2\x80\x86",
				"\xE2\x80\x87",
				"\xE2\x80\x88",
				"\xE2\x80\x89",
				"\xE2\x80\x8A",
				"\xE2\x80\x8B",
				"\xE2\x80\xAF",
				"\xE2\x81\x9F",
				"\xEF\xBB\xBF",
			];
			$str           = str_replace( $special_space, ' ', $str );
		}
	}

	/**
	 * @param WP_Error $wp_error
	 *
	 * @noinspection PhpUnusedPrivateMethodInspection
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
	 */
	private function wp_mail_failed( WP_Error $wp_error ) {
		if ( $this->is_sending ) {
			$this->app->log( $wp_error );
		}
	}

	/**
	 * @param string $from_email
	 *
	 * @return string
	 * @noinspection PhpUnusedPrivateMethodInspection
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
	 */
	private function wp_mail_from( $from_email ) {
		if ( $this->is_sending ) {
			$value = $this->apply_filters( 'mail_from' );
			if ( ! empty( $value ) ) {
				return $value;
			}
		}

		return $from_email;
	}

	/**
	 * @param string $from_name
	 *
	 * @return string
	 * @noinspection PhpUnusedPrivateMethodInspection
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
	 */
	private function wp_mail_from_name( $from_name ) {
		if ( $this->is_sending ) {
			$value = $this->apply_filters( 'mail_from_name' );
			if ( ! empty( $value ) ) {
				return $value;
			}
		}

		return $from_name;
	}
}
