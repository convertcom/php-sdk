<?php

namespace ConvertSdk\Experience\Interfaces;


interface ExperienceManagerInterface
{
    /**
     * Get a list of all experiences.
     *
     * @return array
     */
    public function getList(): array;

    /**
     * Get the experience by key.
     *
     * @param string $key
     * @return mixed
     */
    public function getExperience(string $key);

    /**
     * Get the experience by ID.
     *
     * @param string $id
     * @return mixed
     */
    public function getExperienceById(string $id);

    /**
     * Get experiences by a list of keys.
     *
     * @param array $keys
     * @return array
     */
    public function getExperiences(array $keys): array;

    /**
     * Select a variation for a specific visitor.
     *
     * @param string $visitorId
     * @param string $experienceKey
     * @param array $attributes
     * @return mixed
     */
    public function selectVariation(string $visitorId, string $experienceKey, array $attributes);

    /**
     * Select a variation for a specific visitor using experience ID.
     *
     * @param string $visitorId
     * @param string $experienceId
     * @param array $attributes
     * @return mixed
     */
    public function selectVariationById(string $visitorId, string $experienceId, array $attributes);

    /**
     * Select all variations across all experiences for a specific visitor.
     *
     * @param string $visitorId
     * @param array $attributes
     * @return array
     */
    public function selectVariations(string $visitorId, array $attributes): array;

    /**
     * Get a variation by experience key and variation key.
     *
     * @param string $experienceKey
     * @param string $variationKey
     * @return mixed
     */
    public function getVariation(string $experienceKey, string $variationKey);

    /**
     * Get a variation by experience ID and variation ID.
     *
     * @param string $experienceId
     * @param string $variationId
     * @return mixed
     */
    public function getVariationById(string $experienceId, string $variationId);
}
