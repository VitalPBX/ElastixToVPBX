<?php

function buildpath(array $parts, $separator = DIRECTORY_SEPARATOR) {
	return implode($separator, array_map(function($part) use ($separator) {
		return rtrim($part, $separator);
	}, $parts));
}